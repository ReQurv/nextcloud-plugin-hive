<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\BackgroundJob;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkReplyJobTest extends TestCase {
    private TalkContextService $context;
    private LoggerInterface $logger;
    private ITimeFactory $time;

    protected function setUp(): void {
        $this->context = $this->createMock(TalkContextService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->time = $this->createMock(ITimeFactory::class);
    }

    /**
     * run() is protected; expose it through a thin subclass that shares the
     * same (mocked) dependencies.
     */
    private function runJob(array $argument): void {
        $job = new class($this->time, $this->context, $this->logger) extends TalkReplyJob {
            public function __construct(ITimeFactory $time, TalkContextService $context, LoggerInterface $logger) {
                parent::__construct($time, $context, $logger);
            }

            public function runPublic($argument): void {
                $this->run($argument);
            }
        };
        $job->runPublic($argument);
    }

    public function testSkipsWhenTalkTokenIsMissing(): void {
        $this->context->expects($this->never())->method('getOrCreate');
        $this->runJob(['talkToken' => '', 'actorUserId' => 'alice']);
    }

    public function testSkipsWhenActorIsMissing(): void {
        $this->context->expects($this->never())->method('getOrCreate');
        $this->runJob(['talkToken' => 'roomtoken', 'actorUserId' => '']);
    }

    public function testResolvesBindingAndLogs(): void {
        $conversation = new Conversation();
        $conversation->setId(42);
        $this->context->expects($this->once())
            ->method('getOrCreate')
            ->with('roomtoken', 'alice', 'My Room')
            ->willReturn($conversation);
        $this->logger->expects($this->once())->method('info');

        $this->runJob([
            'talkToken' => 'roomtoken',
            'roomName' => 'My Room',
            'actorUserId' => 'alice',
            'talkMessageId' => 12345,
            'referenceId' => 'ref',
            'backendUrl' => 'https://nextcloud.example',
            'trigger' => 'reply',
            'prompt' => 'hello',
        ]);
    }

    public function testNoPermittedProviderIsWarnedAndSkipped(): void {
        $this->context->method('getOrCreate')
            ->willThrowException(new NoPermittedProviderException('alice'));
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->never())->method('info');

        $this->runJob(['talkToken' => 'roomtoken', 'actorUserId' => 'alice']);
    }

    public function testUnexpectedErrorIsLogged(): void {
        $this->context->method('getOrCreate')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');
        $this->logger->expects($this->never())->method('info');

        $this->runJob(['talkToken' => 'roomtoken', 'actorUserId' => 'alice']);
    }
}
