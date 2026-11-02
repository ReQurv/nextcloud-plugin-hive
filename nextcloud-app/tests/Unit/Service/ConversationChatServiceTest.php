<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Service;

use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\Message;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\ProjectMapper;
use OCA\RequrvHive\Db\ProjectPathMapper;
use OCA\RequrvHive\Service\ChatToolsService;
use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\FileService;
use OCA\RequrvHive\Service\ImageOptimizer;
use OCA\RequrvHive\Service\McpClientService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the ambient-mode branch of send(): the group-chat system note and
 * the NO_REPLY silence sentinel (assistant side dropped from the history).
 */
class ConversationChatServiceTest extends TestCase {
    private ConversationChatService $service;
    private LLMProviderInterface $provider;
    private MessageMapper $messageMapper;
    private IJobList $jobList;
    /** @var list<Message> messages persisted by the mocked mapper */
    private array $inserted;
    private ?string $capturedSystem;

    protected function setUp(): void {
        $this->inserted = [];
        $this->capturedSystem = null;

        $conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->messageMapper->method('insert')->willReturnCallback(function (Message $m): Message {
            $m->setId(count($this->inserted) + 1);
            $this->inserted[] = $m;
            return $m;
        });
        // A fresh conversation: no history to rebuild.
        $this->messageMapper->method('findByConversation')->willReturn([]);
        $messageFileMapper = $this->createMock(MessageFileMapper::class);
        $projectMapper = $this->createMock(ProjectMapper::class);
        $projectPathMapper = $this->createMock(ProjectPathMapper::class);

        $this->provider = $this->createMock(LLMProviderInterface::class);
        $this->provider->method('getCapabilities')->willReturn(['tools' => false]);
        $providerFactory = $this->createMock(LLMProviderFactory::class);
        $providerFactory->method('getProviderForUser')->willReturn($this->provider);

        $fileService = $this->createMock(FileService::class);
        $imageOptimizer = $this->createMock(ImageOptimizer::class);
        $mcpClient = $this->createMock(McpClientService::class);
        $chatTools = $this->createMock(ChatToolsService::class);
        $this->jobList = $this->createMock(IJobList::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ConversationChatService(
            $conversationMapper,
            $this->messageMapper,
            $messageFileMapper,
            $projectMapper,
            $projectPathMapper,
            $providerFactory,
            $fileService,
            $imageOptimizer,
            $mcpClient,
            $chatTools,
            $this->jobList,
            $logger,
        );
    }

    private function makeConversation(): Conversation {
        $conversation = new Conversation();
        $conversation->setId(7);
        $conversation->setUserId('owner');
        $conversation->setTitle('Talk: My Room');
        return $conversation;
    }

    private function respondWith(string $response): void {
        $this->provider->method('chat')->willReturnCallback(
            function (array $messages, ?string $system) use ($response): array {
                $this->capturedSystem = $system;
                return ['response' => $response, 'usage' => []];
            }
        );
    }

    public function testAmbientNoReplyIsNotPersisted(): void {
        $this->respondWith('NO_REPLY');
        // A silent turn must not rebuild the Context Chat index either.
        $this->jobList->expects($this->never())->method('add');

        $result = $this->service->send($this->makeConversation(), 'Carol: nice one', [], 'owner', true);

        $this->assertNull($result['assistantMessage']);
        // Only the room's flow (the user message) stays in the context.
        $this->assertCount(1, $this->inserted);
        $this->assertSame('user', $this->inserted[0]->getRole());
        $this->assertStringContainsString('group chat', (string)$this->capturedSystem);
    }

    public function testAmbientRealReplyIsPersisted(): void {
        $this->respondWith('It shipped this morning');

        $result = $this->service->send($this->makeConversation(), 'Carol: did we ship?', [], 'owner', true);

        $this->assertNotNull($result['assistantMessage']);
        $this->assertSame('It shipped this morning', $result['assistantMessage']->getContent());
        $this->assertCount(2, $this->inserted);
    }

    public function testNonAmbientNoReplyIsARegularReply(): void {
        // Asked directly, the sentinel is just a word: it is answered as-is.
        $this->respondWith('NO_REPLY');

        $result = $this->service->send($this->makeConversation(), 'tell me NO_REPLY', [], 'owner', false);

        $this->assertNotNull($result['assistantMessage']);
        $this->assertSame('NO_REPLY', $result['assistantMessage']->getContent());
    }

    public function testAmbientSystemNoteOnlyOnAmbientTurns(): void {
        $this->respondWith('answer');
        $this->service->send($this->makeConversation(), 'hi', [], 'owner', false);
        $this->assertStringNotContainsString('NO_REPLY', (string)$this->capturedSystem);

        $this->respondWith('answer');
        $this->service->send($this->makeConversation(), 'hi', [], 'owner', true);
        $this->assertStringContainsString(
            ConversationChatService::AMBIENT_SYSTEM_NOTE,
            (string)$this->capturedSystem,
        );
    }

    public function testIsAmbientNoReplyIsTrimmedAndCaseInsensitive(): void {
        $this->assertTrue(ConversationChatService::isAmbientNoReply('NO_REPLY'));
        $this->assertTrue(ConversationChatService::isAmbientNoReply('  no_reply  '));
        $this->assertFalse(ConversationChatService::isAmbientNoReply('NO_REPLY.'));
        $this->assertFalse(ConversationChatService::isAmbientNoReply(''));
        $this->assertFalse(ConversationChatService::isAmbientNoReply('No reply, thanks'));
    }
}
