<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Talk;

use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\Message;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Db\TalkBindingMapper;
use OCA\RequrvHive\Service\ContextChatService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class TalkContextServiceTest extends TestCase {
    private $bindingMapper;
    private $conversationMapper;
    private $messageMapper;
    private $messageFileMapper;
    private $providerFactory;
    private $contextChat;

    protected function setUp(): void {
        $this->bindingMapper = $this->createMock(TalkBindingMapper::class);
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->messageFileMapper = $this->createMock(MessageFileMapper::class);
        $this->providerFactory = $this->createMock(LLMProviderFactory::class);
        $this->contextChat = $this->createMock(ContextChatService::class);
    }

    private function makeService(): TalkContextService {
        return new TalkContextService(
            $this->bindingMapper,
            $this->conversationMapper,
            $this->messageMapper,
            $this->messageFileMapper,
            $this->providerFactory,
            $this->contextChat,
        );
    }

    private function makeProvider(string $id = 'requrv', string $model = 'model-x'): LLMProviderInterface {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getModel')->willReturn($model);
        return $provider;
    }

    // ── getOrCreate ───────────────────────────────────────────────────────

    public function testGetOrCreateReturnsBoundConversation(): void {
        $binding = new TalkBinding();
        $binding->setId(3);
        $binding->setHiveConversationId(9);
        $conversation = new Conversation();
        $conversation->setId(9);

        $this->bindingMapper->method('findByTalkToken')->with('token')->willReturn($binding);
        $this->conversationMapper->expects($this->once())
            ->method('findById')->with(9)
            ->willReturn($conversation);
        $this->conversationMapper->expects($this->never())->method('insert');
        $this->providerFactory->expects($this->never())->method('getProvider');

        $this->assertSame($conversation, $this->makeService()->getOrCreate('token', 'bob'));
    }

    public function testGetOrCreateCreatesConversationAndBinding(): void {
        $this->bindingMapper->method('findByTalkToken')->willReturn(null);
        $this->providerFactory->expects($this->once())
            ->method('getProvider')->with('alice')
            ->willReturn($this->makeProvider());
        $this->conversationMapper->method('insert')->willReturnCallback(
            static function (Conversation $c): Conversation {
                $c->setId(7);
                return $c;
            }
        );

        $inserted = null;
        $this->bindingMapper->method('insert')->willReturnCallback(
            function (TalkBinding $b) use (&$inserted): TalkBinding {
                $inserted = $b;
                return $b;
            }
        );

        $conversation = $this->makeService()->getOrCreate('token', 'alice', 'Sales room');

        $this->assertSame(7, $conversation->getId());
        $this->assertSame('alice', $conversation->getUserId());
        $this->assertSame('Talk: Sales room', $conversation->getTitle());
        $this->assertSame('requrv', $conversation->getProvider());
        $this->assertSame('model-x', $conversation->getModel());

        $this->assertNotNull($inserted);
        $this->assertSame('token', $inserted->getTalkToken());
        $this->assertSame(7, $inserted->getHiveConversationId());
        $this->assertSame('alice', $inserted->getOwnerUserId());
    }

    public function testGetOrCreateDefaultsTitleWithoutRoomName(): void {
        $this->bindingMapper->method('findByTalkToken')->willReturn(null);
        $this->providerFactory->method('getProvider')->willReturn($this->makeProvider());
        $inserted = null;
        $this->conversationMapper->method('insert')->willReturnCallback(
            function (Conversation $c) use (&$inserted): Conversation {
                $c->setId(7);
                $inserted = $c;
                return $c;
            }
        );
        $this->bindingMapper->method('insert')->willReturnArgument(0);

        $this->makeService()->getOrCreate('token', 'alice');
        $this->assertSame('Talk conversation', $inserted->getTitle());
    }

    public function testGetOrCreateRebindsWhenBoundConversationIsGone(): void {
        $stale = new TalkBinding();
        $stale->setId(3);
        $stale->setHiveConversationId(9);

        $this->bindingMapper->method('findByTalkToken')->willReturn($stale);
        $this->conversationMapper->method('findById')->with(9)
            ->willThrowException(new DoesNotExistException('nope'));
        $this->bindingMapper->expects($this->once())->method('delete')->with($stale);

        $this->providerFactory->method('getProvider')->willReturn($this->makeProvider());
        $this->conversationMapper->method('insert')->willReturnCallback(
            static function (Conversation $c): Conversation {
                $c->setId(11);
                return $c;
            }
        );
        $this->bindingMapper->method('insert')->willReturnArgument(0);

        $conversation = $this->makeService()->getOrCreate('token', 'bob');
        $this->assertSame(11, $conversation->getId());
        $this->assertSame('bob', $conversation->getUserId());
    }

    public function testGetOrCreateThrowsWhenFirstUserHasNoProvider(): void {
        $this->bindingMapper->method('findByTalkToken')->willReturn(null);
        $this->providerFactory->method('getProvider')->with('alice')
            ->willThrowException(new NoPermittedProviderException('alice'));
        $this->conversationMapper->expects($this->never())->method('insert');

        $this->expectException(NoPermittedProviderException::class);
        $this->makeService()->getOrCreate('token', 'alice');
    }

    // ── reset ─────────────────────────────────────────────────────────────

    public function testResetCascadesConversationAndBinding(): void {
        $binding = new TalkBinding();
        $binding->setId(3);
        $binding->setHiveConversationId(9);
        $conversation = new Conversation();
        $conversation->setId(9);
        $message = new Message();
        $message->setId(42);

        $this->bindingMapper->method('findByTalkToken')->willReturn($binding);
        $this->conversationMapper->method('findById')->with(9)->willReturn($conversation);
        $this->messageMapper->method('findByConversation')->with(9)->willReturn([$message]);
        $this->messageFileMapper->expects($this->once())->method('deleteByMessage')->with(42);
        $this->messageMapper->expects($this->once())->method('deleteByConversation')->with(9);
        $this->conversationMapper->expects($this->once())->method('delete')->with($conversation);
        $this->contextChat->expects($this->once())->method('removeConversation')->with(9);
        $this->bindingMapper->expects($this->once())->method('delete')->with($binding);

        $this->makeService()->reset('token');
    }

    public function testResetIsNoopWhenUnbound(): void {
        $this->bindingMapper->method('findByTalkToken')->willReturn(null);
        $this->conversationMapper->expects($this->never())->method('findById');
        $this->bindingMapper->expects($this->never())->method('delete');

        $this->makeService()->reset('token');
    }

    public function testResetDropsStaleBindingWhenConversationIsGone(): void {
        $binding = new TalkBinding();
        $binding->setId(3);
        $binding->setHiveConversationId(9);

        $this->bindingMapper->method('findByTalkToken')->willReturn($binding);
        $this->conversationMapper->method('findById')->with(9)
            ->willThrowException(new DoesNotExistException('gone'));
        $this->contextChat->expects($this->never())->method('removeConversation');
        $this->bindingMapper->expects($this->once())->method('delete')->with($binding);

        $this->makeService()->reset('token');
    }

    public function testFindDelegatesToMapper(): void {
        $binding = new TalkBinding();
        $this->bindingMapper->method('findByTalkToken')->with('token')->willReturn($binding);

        $this->assertSame($binding, $this->makeService()->find('token'));
    }
}
