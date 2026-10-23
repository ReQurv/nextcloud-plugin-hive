<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\BackgroundJob;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\Message;
use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
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
    private LLMProviderFactory $providerFactory;
    private LoggerInterface $logger;
    private ITimeFactory $time;

    protected function setUp(): void {
        $this->context = $this->createMock(TalkContextService::class);
        $this->chatService = $this->createMock(ConversationChatService::class);
        $this->talkClient = $this->createMock(TalkBotClient::class);
        $this->providerFactory = $this->createMock(LLMProviderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->time = $this->createMock(ITimeFactory::class);
    }

    /**
     * run() is protected; expose it through a thin subclass that shares the
     * same (mocked) dependencies.
     */
    private function runJob(array $argument): void {
        $job = new class($this->time, $this->context, $this->chatService, $this->talkClient, $this->providerFactory, $this->logger) extends TalkReplyJob {
            public function __construct(
                ITimeFactory $time,
                TalkContextService $context,
                ConversationChatService $chatService,
                TalkBotClient $talkClient,
                LLMProviderFactory $providerFactory,
                LoggerInterface $logger,
            ) {
                parent::__construct($time, $context, $chatService, $talkClient, $providerFactory, $logger);
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

    private function conversation(string $owner, ?string $provider = null, string $model = ''): Conversation {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setUserId($owner);
        $conversation->setProvider($provider);
        $conversation->setModel($model);
        return $conversation;
    }

    private function binding(string $owner): TalkBinding {
        $binding = new TalkBinding();
        $binding->setTalkToken('roomtoken');
        $binding->setHiveConversationId(42);
        $binding->setOwnerUserId($owner);
        return $binding;
    }

    private function provider(string $label, string $model): LLMProviderInterface {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('getModel')->willReturn($model);
        return $provider;
    }

    private function expectPost(string $contains): void {
        $this->talkClient->expects($this->once())
            ->method('sendMessage')
            ->with('roomtoken', $this->stringContains($contains), 'https://nextcloud.example', 12345, false)
            ->willReturn($this->createMock(IResponse::class));
    }

    // ---------------------------------------------------------------- chat ---

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

    public function testRunsTurnAsOwnerAndPostsReply(): void {
        $conversation = $this->conversation('owner');
        $this->context->expects($this->once())
            ->method('getOrCreate')
            ->with('roomtoken', 'alice', 'My Room')
            ->willReturn($conversation);

        // The turn runs as the room owner, not the actor.
        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'hello', [], 'owner')
            ->willReturn($this->sendResult('Hello there'));

        $this->expectPost('Hello there');
        $this->logger->expects($this->once())->method('info');

        $this->runJob($this->baseArgument());
    }

    public function testMentionIsStrippedFromPrompt(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'what is the weather', [], 'owner')
            ->willReturn($this->sendResult('Sunny'));
        $this->expectPost('Sunny');

        $this->runJob($this->baseArgument([
            'trigger' => 'mention',
            'prompt' => '@ReQurv what is the weather',
        ]));
    }

    public function testMentionOnlyIsNotAnswered(): void {
        $conversation = $this->conversation('owner');
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
        $conversation = $this->conversation('owner');
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
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willThrowException(new \RuntimeException('provider blew up'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testModelErrorIsNotPosted(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willReturn($this->sendResult('Error: 500 from provider', '500 from provider'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob($this->baseArgument());
    }

    public function testEmptyAssistantReplyIsNotPosted(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult(''));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob($this->baseArgument());
    }

    public function testMissingBackendUrlIsLogged(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult('Hello there'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument(['backendUrl' => '']));
    }

    public function testPostFailureIsLoggedWithoutRecordingReference(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult('Hello there'));
        $this->talkClient->method('sendMessage')
            ->willThrowException(new \RuntimeException('Talk rejected the bot reply with status 413'));
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->baseArgument());
    }

    public function testLongReplyIsTruncatedToTalkLimit(): void {
        $conversation = $this->conversation('owner');
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

    // -------------------------------------------------------------- commands ---

    public function testHelpCommandRepliesWithHelp(): void {
        $this->chatService->expects($this->never())->method('send');
        $this->expectPost('ReQurv commands');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/help']));
    }

    public function testUnknownCommandRepliesUnknown(): void {
        $this->chatService->expects($this->never())->method('send');
        $this->expectPost('Unknown command /frobnicate');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/frobnicate']));
    }

    public function testNewCommandUnbound(): void {
        $this->context->method('find')->willReturn(null);
        $this->context->expects($this->never())->method('reset');
        $this->expectPost('There is no conversation');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/new', 'actorUserId' => 'alice']));
    }

    public function testNewCommandByNonOwnerIsRejected(): void {
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->context->expects($this->never())->method('reset');
        $this->expectPost('Only the room owner');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/new', 'actorUserId' => 'alice']));
    }

    public function testNewCommandByOwnerResets(): void {
        $this->context->method('find')->willReturn($this->binding('alice'));
        $this->context->expects($this->once())->method('reset')->with('roomtoken');
        $this->expectPost('Conversation reset');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/new', 'actorUserId' => 'alice']));
    }

    public function testModelShowWithoutConversation(): void {
        $this->context->method('findConversation')->willReturn(null);
        $this->expectPost('No conversation in this room yet');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model']));
    }

    public function testModelShowCurrent(): void {
        $conversation = $this->conversation('owner', 'openai', 'gpt-x');
        $this->context->method('findConversation')->willReturn($conversation);
        $this->providerFactory->method('isKnownProviderId')->willReturn(true);
        $this->providerFactory->method('getProviderById')->willReturn($this->provider('OpenAI', 'gpt-x'));
        $this->expectPost('Current model: OpenAI — gpt-x');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model']));
    }

    public function testModelSwitchByNonOwnerIsRejected(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->context->expects($this->never())->method('setProvider');
        $this->expectPost('Only the room owner can change the model');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model openai', 'actorUserId' => 'alice']));
    }

    public function testModelSwitchUnknownProvider(): void {
        $conversation = $this->conversation('alice');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->providerFactory->method('isKnownProviderId')->willReturn(false);
        $this->providerFactory->method('getProviderIdsForUser')->willReturn(['openai']);
        $this->providerFactory->method('getProviderById')->willReturn($this->provider('OpenAI', 'gpt-x'));
        $this->context->expects($this->never())->method('setProvider');
        $this->expectPost('Unknown provider "bogus"');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model bogus', 'actorUserId' => 'alice']));
    }

    public function testModelSwitchNotAllowedForOwner(): void {
        $conversation = $this->conversation('alice');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->providerFactory->method('isKnownProviderId')->willReturn(true);
        $this->providerFactory->method('isAllowedForUser')->willReturn(false);
        $this->context->expects($this->never())->method('setProvider');
        $this->expectPost('not permitted to use provider "openai"');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model openai', 'actorUserId' => 'alice']));
    }

    public function testModelSwitchSuccess(): void {
        $conversation = $this->conversation('alice', 'anthropic', 'claude-x');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->providerFactory->method('isKnownProviderId')->willReturn(true);
        $this->providerFactory->method('isAllowedForUser')->willReturn(true);
        $this->providerFactory->method('getProviderById')->willReturn($this->provider('OpenAI', 'gpt-x'));
        $this->context->expects($this->once())->method('setProvider')->with('roomtoken', 'openai');
        $this->expectPost('Switched to OpenAI — gpt-x');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/model openai', 'actorUserId' => 'alice']));
    }
}
