<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\BackgroundJob;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\Message;
use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Talk\TalkBotClient;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkReplyJobTest extends TestCase {
    private TalkContextService $context;
    private ConversationChatService $chatService;
    private TalkBotClient $talkClient;
    private LoggerInterface $logger;
    private ITimeFactory $time;

    protected function setUp(): void {
        $this->context = $this->createMock(TalkContextService::class);
        $this->chatService = $this->createMock(ConversationChatService::class);
        $this->talkClient = $this->createMock(TalkBotClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->time = $this->createMock(ITimeFactory::class);
    }

    /**
     * run() is protected; expose it through a thin subclass that shares the
     * same (mocked) dependencies.
     */
    private function runJob(array $argument): void {
        $job = new class($this->time, $this->context, $this->chatService, $this->talkClient, $this->logger) extends TalkReplyJob {
            public function __construct(
                ITimeFactory $time,
                TalkContextService $context,
                ConversationChatService $chatService,
                TalkBotClient $talkClient,
                LoggerInterface $logger,
            ) {
                parent::__construct($time, $context, $chatService, $talkClient, $logger);
            }

            public function runPublic($argument): void {
                $this->run($argument);
            }
        };
        $job->runPublic($argument);
    }

    private function baseArgument(array $overrides = []): array {
        return array_merge([
            'talkToken' => 'roomtoken',
            'roomName' => 'My Room',
            'prompt' => 'hello',
            'actorUserId' => 'alice',
            'talkMessageId' => 12345,
            'referenceId' => 'ref-abc',
            'backendUrl' => 'https://nextcloud.example',
            'trigger' => 'reply',
        ], $overrides);
    }

    private function sendResult(string $reply, ?string $modelError = null): array {
        $assistant = new Message();
        $assistant->setId(99);
        $assistant->setRole('assistant');
        $assistant->setContent($reply);
        $conversation = new Conversation();
        $conversation->setId(42);
        return [
            'userMessage' => new Message(),
            'files' => [],
            'assistantMessage' => $assistant,
            'conversation' => $conversation,
            'modelError' => $modelError,
        ];
    }

    public function testSkipsWhenTalkTokenIsMissing(): void {
        $this->context->expects($this->never())->method('getOrCreate');
        $this->chatService->expects($this->never())->method('send');
        $this->runJob($this->baseArgument(['talkToken' => '']));
    }

    public function testSkipsWhenActorIsMissing(): void {
        $this->context->expects($this->never())->method('getOrCreate');
        $this->chatService->expects($this->never())->method('send');
        $this->runJob($this->baseArgument(['actorUserId' => '']));
    }

    public function testCommandTriggerIsSkippedNotFedToModel(): void {
        $this->context->expects($this->never())->method('getOrCreate');
        $this->chatService->expects($this->never())->method('send');
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('debug');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/new']));
    }

    public function testRunsTurnAsOwnerAndPostsReply(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner'); // room owner, not the triggering alice
        $this->context->expects($this->once())
            ->method('getOrCreate')
            ->with('roomtoken', 'alice', 'My Room')
            ->willReturn($conversation);

        // The turn runs as the room owner, not the actor.
        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'hello', [], 'owner')
            ->willReturn($this->sendResult('Hello there'));

        $this->talkClient->expects($this->once())
            ->method('sendMessage')
            ->with('roomtoken', 'Hello there', 'https://nextcloud.example', 12345, 'ref-abc', false)
            ->willReturn($this->createMock(IResponse::class));
        $this->context->expects($this->once())
            ->method('recordBotReference')
            ->with('roomtoken', 'ref-abc');
        $this->logger->expects($this->once())->method('info');

        $this->runJob($this->baseArgument());
    }

    public function testMentionIsStrippedFromPrompt(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);

        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'what is the weather', [], 'owner')
            ->willReturn($this->sendResult('Sunny'));
        $this->talkClient->expects($this->once())
            ->method('sendMessage')
            ->willReturn($this->createMock(IResponse::class));

        $this->runJob($this->baseArgument([
            'trigger' => 'mention',
            'prompt' => '@ReQurv what is the weather',
        ]));
    }

    public function testMentionOnlyIsNotAnswered(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->expects($this->never())->method('send');
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('debug');

        $this->runJob($this->baseArgument(['trigger' => 'mention', 'prompt' => '@ReQurv']));
    }

    public function testNoPermittedProviderAtGetOrCreate(): void {
        $this->context->method('getOrCreate')
            ->willThrowException(new NoPermittedProviderException('alice'));
        $this->chatService->expects($this->never())->method('send');
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testNoPermittedProviderAtSend(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willThrowException(new NoPermittedProviderException('owner'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testUnexpectedErrorAtGetOrCreate(): void {
        $this->context->method('getOrCreate')
            ->willThrowException(new \RuntimeException('db down'));
        $this->chatService->expects($this->never())->method('send');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testUnexpectedErrorAtSend(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willThrowException(new \RuntimeException('provider blew up'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testModelErrorIsNotPosted(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willReturn($this->sendResult('Error: 500 from provider', '500 from provider'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->context->expects($this->never())->method('recordBotReference');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob($this->baseArgument());
    }

    public function testEmptyAssistantReplyIsNotPosted(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult(''));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->context->expects($this->never())->method('recordBotReference');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob($this->baseArgument());
    }

    public function testMissingBackendUrlIsLogged(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult('Hello there'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument(['backendUrl' => '']));
    }

    public function testPostFailureIsLoggedWithoutRecordingReference(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult('Hello there'));
        $this->talkClient->method('sendMessage')
            ->willThrowException(new \RuntimeException('Talk rejected the bot reply with status 413'));
        $this->context->expects($this->never())->method('recordBotReference');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testLongReplyIsTruncatedToTalkLimit(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $long = str_repeat('a', 40000);
        $this->chatService->method('send')->willReturn($this->sendResult($long));

        $posted = null;
        $this->talkClient->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function (string $talkToken, string $message) use (&$posted): IResponse {
                $posted = $message;
                return $this->createMock(IResponse::class);
            });

        $this->runJob($this->baseArgument());

        $this->assertNotNull($posted);
        $this->assertSame(32000, mb_strlen($posted));
        $this->assertStringEndsWith('…', (string)$posted);
    }
}
