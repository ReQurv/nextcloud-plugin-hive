<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Talk;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Talk\TalkBotListener;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotListenerTest extends TestCase {
    private IJobList $jobList;
    private IURLGenerator $urlGenerator;
    private ISecureRandom $secureRandom;
    private LoggerInterface $logger;
    private TalkBotListener $listener;
    /** @var array<string, mixed>|null last job data passed to IJobList::add */
    private ?array $queuedData;
    private int $queuedJobClassCalls;

    protected function setUp(): void {
        $this->queuedData = null;
        $this->queuedJobClassCalls = 0;

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
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->secureRandom->method('generate')->willReturn('reference-id-32-bytes-long-000000');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new TalkBotListener(
            $this->jobList,
            $this->urlGenerator,
            $this->secureRandom,
            $this->logger,
        );
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
        $this->assertSame('reference-id-32-bytes-long-000000', $this->queuedData['referenceId']);
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

    public function testReplyTriggerWinsOverCommand(): void {
        // A command that also replies to the bot is still a reply.
        $this->invoke('/new', objectOverrides: [
            'inReplyTo' => ['actor' => ['id' => TalkBotRegistration::botActorRef()]],
        ]);
        $this->assertQueued('reply');
    }
}
