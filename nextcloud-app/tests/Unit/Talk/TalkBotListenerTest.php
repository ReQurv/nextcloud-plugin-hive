<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Talk;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Talk\TalkBotListener;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotListenerTest extends TestCase {
    private IJobList $jobList;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private TalkContextService $context;
    private TalkBotListener $listener;
    /** @var array<string, mixed>|null last job data passed to IJobList::add */
    private ?array $queuedData;
    private int $queuedJobClassCalls;
    // The find()/claimAmbientTurn() stubs are callback-driven: PHPUnit's
    // first-registered matcher wins, so a per-test method() re-stub could not
    // override a fixed setUp value.
    private ?TalkBinding $foundBinding;
    private bool $claimResult;

    protected function setUp(): void {
        $this->queuedData = null;
        $this->queuedJobClassCalls = 0;
        $this->foundBinding = null;
        $this->claimResult = true;

        $this->jobList = $this->createMock(IJobList::class);
        $this->jobList->method('add')->willReturnCallback(
            function ($job, ?array $data = null): void {
                $this->queuedJobClassCalls++;
                $this->assertThat($job, $this->identicalTo(TalkReplyJob::class));
                $this->queuedData = $data;
            }
        );
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('getBaseUrl')->willReturn('https://nextcloud.example/nextcloud/');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->context = $this->createMock(TalkContextService::class);
        // By default the room is unbound: ambient mode is off and the
        // recent-messages window is never touched.
        $this->context->method('find')->willReturnCallback(fn (): ?TalkBinding => $this->foundBinding);
        $this->context->method('claimAmbientTurn')->willReturnCallback(fn (): bool => $this->claimResult);

        $this->listener = new TalkBotListener(
            $this->jobList,
            $this->urlGenerator,
            $this->logger,
            $this->context,
        );
    }

    private function autoBinding(bool $autoReply = true): TalkBinding {
        $binding = new TalkBinding();
        $binding->setTalkToken('roomtoken');
        $binding->setAutoReply($autoReply);
        return $binding;
    }

    /**
     * Build an ActivityStream body as Talk would send it in a BotInvokeEvent.
     *
     * @param array<string, mixed> $objectOverrides
     * @param array<string, mixed> $topOverrides
     */
    private function invoke(string $text, array $objectOverrides = [], array $topOverrides = [], string $botUrl = TalkBotRegistration::BOT_URL): void {
        $object = array_merge([
            'type' => 'Note',
            'id' => '12345',
            'name' => 'message',
            'content' => json_encode(['message' => $text, 'parameters' => []]),
            'mediaType' => 'text/markdown',
        ], $objectOverrides);

        $message = array_merge([
            'type' => 'Create',
            'actor' => ['type' => 'Person', 'id' => 'users/alice', 'name' => 'Alice', 'talkParticipantType' => '3'],
            'object' => $object,
            'target' => ['type' => 'Collection', 'id' => 'roomtoken', 'name' => 'My Room'],
        ], $topOverrides);

        $this->listener->processInvocation($botUrl, $message, 'https://nextcloud.example/nextcloud');
    }

    private function assertNotQueued(): void {
        $this->assertSame(0, $this->queuedJobClassCalls, 'expected no job to be queued');
    }

    private function assertQueued(string $trigger, ?string $prompt = null): void {
        $this->assertSame(1, $this->queuedJobClassCalls, 'expected exactly one job to be queued');
        $this->assertIsArray($this->queuedData);
        $this->assertSame('roomtoken', $this->queuedData['talkToken']);
        $this->assertSame('My Room', $this->queuedData['roomName']);
        $this->assertSame('alice', $this->queuedData['actorUserId']);
        $this->assertSame(12345, $this->queuedData['talkMessageId']);
        $this->assertSame('https://nextcloud.example/nextcloud', $this->queuedData['backendUrl']);
        $this->assertSame($trigger, $this->queuedData['trigger']);
        if ($prompt !== null) {
            $this->assertSame($prompt, $this->queuedData['prompt']);
        }
    }

    public function testHandleIgnoresUnrelatedEvents(): void {
        $this->listener->handle(new Event());
        $this->assertNotQueued();
    }

    public function testIgnoresOtherBots(): void {
        $this->invoke('/new', botUrl: 'nextcloudapp://other-app');
        $this->assertNotQueued();
    }

    public function testIgnoresNonCreateEvents(): void {
        $this->invoke('/new', topOverrides: ['type' => 'Join']);
        $this->assertNotQueued();
    }

    public function testIgnoresSystemMessages(): void {
        $this->invoke('/new', objectOverrides: ['name' => 'system']);
        $this->assertNotQueued();
    }

    public function testIgnoresEmptyMessage(): void {
        $this->invoke('   ');
        $this->assertNotQueued();
    }

    public function testIgnoresMessageWithoutTrigger(): void {
        $this->invoke('just chatting between people');
        $this->assertNotQueued();
    }

    public function testIgnoresNonUserActors(): void {
        // Guests have no account to run the turn as.
        $this->invoke('/new', topOverrides: [
            'actor' => ['type' => 'Person', 'id' => 'guests/abc123', 'name' => 'Guest'],
        ]);
        $this->assertNotQueued();
    }

    public function testQueuesOnReplyToBot(): void {
        $this->invoke('what is this?', objectOverrides: [
            'inReplyTo' => [
                'actor' => ['type' => 'Person', 'id' => TalkBotRegistration::botActorRef(), 'name' => 'ReQurv'],
                'object' => ['type' => 'Note', 'id' => '111'],
            ],
        ]);
        $this->assertQueued('reply', 'what is this?');
    }

    public function testQueuesOnCommand(): void {
        $this->invoke('/new');
        $this->assertQueued('command', '/new');
    }

    public function testQueuesOnMention(): void {
        $this->invoke('please @ReQurv summarize the deck');
        $this->assertQueued('mention', 'please @ReQurv summarize the deck');
    }

    public function testMentionRequiresWordBoundary(): void {
        // "@ReQurvx" is a different word, not a mention of the bot.
        $this->invoke('looking at @ReQurvx in the docs');
        $this->assertNotQueued();
    }

    public function testQueuesOnAiAliasMention(): void {
        $this->invoke('hey @ai summarize the deck');
        $this->assertQueued('mention', 'hey @ai summarize the deck');
    }

    public function testAiAliasAlsoRequiresWordBoundary(): void {
        // "@airport" contains "@ai" but is a different word.
        $this->invoke('checking the @airport signs');
        $this->assertNotQueued();
    }

    public function testReplyTriggerWinsOverCommand(): void {
        // A command that also replies to the bot is still a reply.
        $this->invoke('/new', objectOverrides: [
            'inReplyTo' => ['actor' => ['id' => TalkBotRegistration::botActorRef()]],
        ]);
        $this->assertQueued('reply');
    }

    // ---------------------------------------------------------------- ambient ---

    public function testIgnoresUnaddressedMessageWhenRoomUnbound(): void {
        // Default foundBinding is null: the room is unbound.
        $this->context->expects($this->never())->method('appendRecentMessage');

        $this->invoke('just chatting between people');

        $this->assertNotQueued();
    }

    public function testIgnoresUnaddressedMessageWhenAutoOff(): void {
        $this->foundBinding = $this->autoBinding(autoReply: false);
        $this->context->expects($this->never())->method('appendRecentMessage');
        $this->context->expects($this->never())->method('claimAmbientTurn');

        $this->invoke('just chatting between people');

        $this->assertNotQueued();
    }

    public function testAmbientMessageQueuesTurnWhenAutoOn(): void {
        $this->foundBinding = $this->autoBinding();
        $this->context->expects($this->once())
            ->method('appendRecentMessage')
            ->with('roomtoken', 'Alice', 'what do you think of the plan');
        $this->context->expects($this->once())->method('claimAmbientTurn')->with('roomtoken');

        $this->invoke('what do you think of the plan');

        $this->assertQueued('ambient', 'what do you think of the plan');
        $this->assertSame('Alice', $this->queuedData['actorName']);
    }

    public function testAmbientShortMessageIsIgnored(): void {
        $this->foundBinding = $this->autoBinding();
        $this->context->expects($this->never())->method('claimAmbientTurn');

        $this->invoke('ok');

        $this->assertNotQueued();
    }

    public function testAmbientMessageHeldAsPendingWhenRoomBusy(): void {
        $this->foundBinding = $this->autoBinding();
        $this->claimResult = false;
        $this->context->expects($this->once())
            ->method('setAutoPending')
            ->with('roomtoken', $this->callback(
                static fn (string $payload): bool => (string)json_decode($payload, true)['prompt'] === 'third one in the queue'
            ));
        $this->logger->expects($this->once())->method('debug');

        $this->invoke('third one in the queue');

        $this->assertNotQueued();
    }

    public function testAmbientMessageFromGuestIsIgnored(): void {
        $this->foundBinding = $this->autoBinding();
        $this->context->expects($this->never())->method('claimAmbientTurn');

        // The window still sees the guest (it is room flow), but no turn runs
        // under a guest account.
        $this->context->expects($this->once())
            ->method('appendRecentMessage')
            ->with('roomtoken', 'Guest', 'hello from a guest');

        $this->invoke('hello from a guest', topOverrides: [
            'actor' => ['type' => 'Person', 'id' => 'guests/abc123', 'name' => 'Guest'],
        ]);

        $this->assertNotQueued();
    }

    public function testAmbientWindowIncludesBotOwnMessages(): void {
        $this->foundBinding = $this->autoBinding();
        $this->context->expects($this->once())
            ->method('appendRecentMessage')
            ->with('roomtoken', 'ReQurv', 'an earlier bot answer');
        $this->context->expects($this->never())->method('claimAmbientTurn');

        $this->invoke('an earlier bot answer', topOverrides: [
            'actor' => ['type' => 'Person', 'id' => 'bots/' . substr(TalkBotRegistration::botActorId(), 4), 'name' => 'ReQurv'],
        ]);

        $this->assertNotQueued();
    }

    public function testExplicitTriggersDoNotUseAmbientLane(): void {
        // An addressed message in an auto room is a plain mention turn: no
        // claim, no pending — the lane only governs unaddressed traffic.
        $this->foundBinding = $this->autoBinding();
        $this->context->expects($this->never())->method('claimAmbientTurn');
        $this->context->expects($this->never())->method('setAutoPending');

        $this->invoke('hey @ai tell me about the deck');

        $this->assertQueued('mention');
    }
}
