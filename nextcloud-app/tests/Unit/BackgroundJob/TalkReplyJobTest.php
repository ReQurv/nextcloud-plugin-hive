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

    private function silentResult(): array {
        $conversation = new Conversation();
        $conversation->setId(42);
        return [
            'userMessage' => new Message(),
            'files' => [],
            'assistantMessage' => null,
            'conversation' => $conversation,
            'modelError' => null,
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
            ->with($conversation, 'hello', [], 'owner', false)
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
            ->with($conversation, 'what is the weather', [], 'owner', false)
            ->willReturn($this->sendResult('Sunny'));
        $this->expectPost('Sunny');

        $this->runJob($this->baseArgument([
            'trigger' => 'mention',
            'prompt' => '@ReQurv what is the weather',
        ]));
    }

    public function testAiAliasMentionIsStrippedFromPrompt(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'what is the weather', [], 'owner', false)
            ->willReturn($this->sendResult('Sunny'));
        $this->expectPost('Sunny');

        $this->runJob($this->baseArgument([
            'trigger' => 'mention',
            'prompt' => '@ai what is the weather',
        ]));
    }

    public function testAiAliasMentionOnlyIsNotAnswered(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->expects($this->never())->method('send');
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('debug');

        $this->runJob($this->baseArgument(['trigger' => 'mention', 'prompt' => '@ai']));
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

    // --------------------------------------------------------------- ambient ---

    public function testAmbientTurnPostsReplyAndReleasesLane(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->chatService->expects($this->once())
            ->method('send')
            ->with($conversation, 'Alice: hello there', [], 'owner', true)
            ->willReturn($this->sendResult('Hello there'));
        $this->context->expects($this->once())->method('releaseAmbientTurn')->with('roomtoken');
        $this->context->method('consumeAutoPending')->willReturn(null);
        $this->expectPost('Hello there');

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'hello there',
        ]));
    }

    public function testAmbientPromptIncludesRoomWindow(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $binding = $this->binding('owner');
        $binding->setRecentMessages(json_encode([
            ['name' => 'Alice', 'text' => 'did we ship the fix?'],
            ['name' => 'Bob', 'text' => 'yes, this morning'],
        ]));
        $this->context->method('find')->willReturn($binding);
        $this->context->method('consumeAutoPending')->willReturn(null);
        $this->chatService->expects($this->once())
            ->method('send')
            ->with(
                $conversation,
                $this->callback(static fn (string $p): bool => str_contains($p, 'Recent messages in this room:')
                    && str_contains($p, 'Bob: yes, this morning')
                    && str_contains($p, 'Current message from Carol: when did the fix ship?')),
                [],
                'owner',
                true,
            )
            ->willReturn($this->sendResult('It is in the changelog'));
        $this->expectPost('It is in the changelog');

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Carol',
            'prompt' => 'when did the fix ship?',
        ]));
    }

    public function testAmbientSilentTurnIsNotPosted(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->chatService->method('send')->willReturn($this->silentResult());
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->context->expects($this->once())->method('releaseAmbientTurn')->with('roomtoken');
        $this->logger->expects($this->once())->method('debug');

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'nice one',
        ]));
    }

    public function testAmbientModelErrorStillReleasesLane(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')
            ->willReturn($this->sendResult('Error: 500 from provider', '500 from provider'));
        $this->talkClient->expects($this->never())->method('sendMessage');
        $this->context->expects($this->once())->method('releaseAmbientTurn')->with('roomtoken');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'what is going on',
        ]));
    }

    public function testAmbientLaneIsReleasedWhenNoProvider(): void {
        $this->context->method('getOrCreate')
            ->willThrowException(new NoPermittedProviderException('owner'));
        $this->chatService->expects($this->never())->method('send');
        $this->context->expects($this->once())->method('releaseAmbientTurn')->with('roomtoken');
        $this->context->expects($this->once())->method('consumeAutoPending')->with('roomtoken');

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'what is going on',
        ]));
    }

    public function testNonAmbientTurnDoesNotTouchAmbientLane(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->chatService->method('send')->willReturn($this->sendResult('Hello there'));
        $this->context->expects($this->never())->method('releaseAmbientTurn');
        $this->context->expects($this->never())->method('consumeAutoPending');
        $this->expectPost('Hello there');

        $this->runJob($this->baseArgument());
    }

    public function testAmbientCatchUpRunsPendingMessage(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->context->method('consumeAutoPending')
            ->willReturn([
                'talkToken' => 'roomtoken',
                'roomName' => 'My Room',
                'prompt' => 'the follow-up question',
                'actorUserId' => 'bob',
                'actorName' => 'Bob',
                'talkMessageId' => 22222,
                'backendUrl' => 'https://nextcloud.example',
                'trigger' => 'ambient',
            ], null);
        $this->context->method('claimAmbientTurn')->willReturn(true);
        $this->context->expects($this->once())->method('claimAmbientTurn')->with('roomtoken');
        $this->context->expects($this->exactly(2))->method('releaseAmbientTurn')->with('roomtoken');
        $this->chatService->method('send')
            ->willReturn($this->sendResult('First answer'), $this->sendResult('Second answer'));

        $posted = [];
        $this->talkClient->expects($this->exactly(2))
            ->method('sendMessage')
            ->willReturnCallback(function (string $talkToken, string $message, string $backendUrl, int $replyTo) use (&$posted): IResponse {
                $posted[] = [$message, $replyTo];
                return $this->createMock(IResponse::class);
            });

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'the first question',
        ]));

        $this->assertCount(2, $posted);
        $this->assertSame('First answer', $posted[0][0]);
        $this->assertSame(12345, $posted[0][1]);
        $this->assertSame('Second answer', $posted[1][0]);
        $this->assertSame(22222, $posted[1][1]);
    }

    public function testAmbientCatchUpGivesUpAfterLimit(): void {
        $conversation = $this->conversation('owner');
        $this->context->method('getOrCreate')->willReturn($conversation);
        $this->context->method('find')->willReturn($this->binding('owner'));
        $pending = static fn (): array => [
            'talkToken' => 'roomtoken',
            'roomName' => 'My Room',
            'prompt' => 'another one',
            'actorUserId' => 'bob',
            'actorName' => 'Bob',
            'talkMessageId' => 33333,
            'backendUrl' => 'https://nextcloud.example',
            'trigger' => 'ambient',
        ];
        // The room keeps holding a pending message: the job must stop after
        // its turn plus the catch-up limit, not loop forever.
        $this->context->method('consumeAutoPending')->willReturnCallback($pending);
        $this->context->method('claimAmbientTurn')->willReturn(true);
        $this->context->expects($this->exactly(3))->method('releaseAmbientTurn')->with('roomtoken');

        $sends = 0;
        $this->chatService->method('send')->willReturnCallback(function () use (&$sends): array {
            $sends++;
            return $this->sendResult('ok');
        });
        $this->talkClient->method('sendMessage')->willReturn($this->createMock(IResponse::class));

        $this->runJob($this->baseArgument([
            'trigger' => 'ambient',
            'actorName' => 'Alice',
            'prompt' => 'the first question',
        ]));

        // 1 initial turn + AMBIENT_CATCH_UP_LIMIT catch-ups; whatever is
        // still held stays in the lane for the next trigger.
        $this->assertSame(1 + TalkContextService::AMBIENT_CATCH_UP_LIMIT, $sends);
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

    public function testThinkingShowWithoutConversation(): void {
        $this->context->method('findConversation')->willReturn(null);
        $this->expectPost('No conversation in this room yet');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking']));
    }

    public function testThinkingShowState(): void {
        $conversation = $this->conversation('owner');
        $conversation->setThinking(false);
        $this->context->method('findConversation')->willReturn($conversation);
        $this->expectPost('Thinking: off');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking']));
    }

    public function testThinkingToggleByNonOwnerIsRejected(): void {
        $this->context->method('findConversation')->willReturn($this->conversation('owner'));
        $this->context->expects($this->never())->method('setThinking');
        $this->expectPost('Only the room owner can change thinking');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking off', 'actorUserId' => 'alice']));
    }

    public function testThinkingToggleOffByOwner(): void {
        $this->context->method('findConversation')->willReturn($this->conversation('alice'));
        $this->context->expects($this->once())->method('setThinking')->with('roomtoken', false);
        $this->expectPost('Thinking disabled');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking off', 'actorUserId' => 'alice']));
    }

    public function testThinkingToggleOnByOwner(): void {
        $this->context->method('findConversation')->willReturn($this->conversation('alice'));
        $this->context->expects($this->once())->method('setThinking')->with('roomtoken', true);
        $this->expectPost('Thinking enabled');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking on', 'actorUserId' => 'alice']));
    }

    public function testThinkingInvalidArgument(): void {
        $this->context->method('findConversation')->willReturn($this->conversation('alice'));
        $this->context->expects($this->never())->method('setThinking');
        $this->expectPost('Usage: /thinking on|off');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/thinking maybe', 'actorUserId' => 'alice']));
    }

    public function testAutoShowWithoutConversation(): void {
        $this->context->method('find')->willReturn(null);
        $this->expectPost('No conversation in this room yet');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto']));
    }

    public function testAutoShowOff(): void {
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->expectPost('Ambient mode: off');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto']));
    }

    public function testAutoShowOn(): void {
        $binding = $this->binding('owner');
        $binding->setAutoReply(true);
        $this->context->method('find')->willReturn($binding);
        $this->expectPost('Ambient mode: on');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto']));
    }

    public function testAutoToggleByNonOwnerIsRejected(): void {
        $this->context->method('find')->willReturn($this->binding('owner'));
        $this->context->expects($this->never())->method('setAutoReply');
        $this->expectPost('Only the room owner can change ambient mode');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto on', 'actorUserId' => 'alice']));
    }

    public function testAutoToggleOnByOwner(): void {
        $this->context->method('find')->willReturn($this->binding('alice'));
        $this->context->expects($this->once())->method('setAutoReply')->with('roomtoken', true);
        $this->expectPost('Ambient mode enabled');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto on', 'actorUserId' => 'alice']));
    }

    public function testAutoToggleOffByOwner(): void {
        $binding = $this->binding('alice');
        $binding->setAutoReply(true);
        $this->context->method('find')->willReturn($binding);
        $this->context->expects($this->once())->method('setAutoReply')->with('roomtoken', false);
        $this->expectPost('Ambient mode disabled');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto off', 'actorUserId' => 'alice']));
    }

    public function testAutoInvalidArgument(): void {
        $this->context->method('find')->willReturn($this->binding('alice'));
        $this->context->expects($this->never())->method('setAutoReply');
        $this->expectPost('Usage: /auto on|off');

        $this->runJob($this->baseArgument(['trigger' => 'command', 'prompt' => '/auto maybe', 'actorUserId' => 'alice']));
    }
}
