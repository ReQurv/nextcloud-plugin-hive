<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Cowork;

use OCA\RequrvHive\Cowork\DocsSummarizeFolderTaskType;
use OCA\RequrvHive\Db\Coworker;
use OCA\RequrvHive\Db\CoworkerRun;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the "docs" task family: which files it picks up, what it asks
 * the model, and how it accounts for the results it writes.
 */
class BatchTextTaskTypeTest extends TestCase {
    private $rootFolder;
    private $providerFactory;
    private $tagManager;
    private $tagObjectMapper;
    private $logger;
    private $provider;
    private $userFolder;
    private DocsSummarizeFolderTaskType $task;

    /** @var array<string, string> written output path => content */
    private array $written = [];

    protected function setUp(): void {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->providerFactory = $this->createMock(LLMProviderFactory::class);
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = $this->createMock(LLMProviderInterface::class);
        $this->provider->method('getId')->willReturn('hive');
        $this->provider->method('ask')->willReturn(['response' => 'a summary']);
        $this->providerFactory->method('getProviderForUser')->willReturn($this->provider);

        $this->userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

        $this->task = new DocsSummarizeFolderTaskType(
            $this->rootFolder,
            $this->providerFactory,
            $this->tagManager,
            $this->tagObjectMapper,
            $this->logger,
        );
    }

    private function coworker(array $options = []): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('docs:summarize');
        $cw->setCronSchedule('0 2 * * *');
        $cw->setInputPath('/Documents');
        $cw->setOptions(json_encode($options));
        (new \ReflectionProperty($cw, 'id'))->setValue($cw, 7);
        return $cw;
    }

    private function newRun(): CoworkerRun {
        $run = new CoworkerRun();
        $run->setCoworkerId(7);
        $run->setUserId('alice');
        return $run;
    }

    /**
     * A source file in a parent folder that records what gets written to it.
     */
    private function file(int $id, string $name, string $mime, string $content, int $mtime = 100): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/Documents/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getParent')->willReturn($this->outputParent());
        return $file;
    }

    private ?Folder $parent = null;

    private function outputParent(): Folder {
        if ($this->parent !== null) {
            return $this->parent;
        }
        $parent = $this->createMock(Folder::class);
        $parent->method('nodeExists')->willReturnCallback(
            fn (string $name): bool => isset($this->written[$name])
        );
        $parent->method('get')->willReturnCallback(function (string $name) {
            $existing = $this->createMock(File::class);
            $existing->method('getMTime')->willReturn(0);
            $existing->method('putContent')->willReturnCallback(function ($c) use ($name) {
                $this->written[$name] = $c;
            });
            return $existing;
        });
        $parent->method('newFile')->willReturnCallback(function (string $name, $content = null) {
            $this->written[$name] = (string)$content;
            return $this->createMock(File::class);
        });
        $this->parent = $parent;
        return $parent;
    }

    /** @param list<File> $files */
    private function inputFolder(array $files): void {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($files);
        $this->userFolder->method('get')->willReturn($folder);
    }

    private function progress(): callable {
        return static function (int $processed, int $total): void {
        };
    }

    // ── Execution ─────────────────────────────────────────────────────────

    public function testRunProcessesEveryFileSynchronously(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'first body'),
            $this->file(12, 'b.md', 'text/markdown', 'second body'),
        ]);

        $seen = [];
        $this->provider->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function (string $prompt) use (&$seen): array {
                $seen[] = $prompt;
                return ['response' => 'a summary'];
            });

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertArrayNotHasKey('pending', $result);
        $this->assertArrayNotHasKey('state', $result);
        $this->assertSame(2, $result['itemsTotal']);
        $this->assertSame(2, $result['itemsProcessed']);
        $this->assertSame('a summary', $this->written['a.summary.md']);
        $this->assertSame('a summary', $this->written['b.summary.md']);
        $this->assertStringContainsString('first body', $seen[0]);
        $this->assertStringContainsString('second body', $seen[1]);
    }

    public function testNonMatchingMimeTypesAreIgnored(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'photo.jpg', 'image/jpeg', 'binary'),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertArrayHasKey('a.summary.md', $this->written);
        $this->assertArrayNotHasKey('photo.summary.jpg', $this->written);
    }

    /** Half a document summarised as if it were the whole one is worse than none. */
    public function testOversizedFilesAreSkippedRatherThanTruncated(): void {
        $this->inputFolder([
            $this->file(11, 'small.txt', 'text/plain', 'body'),
            $this->file(12, 'huge.txt', 'text/plain', str_repeat('x', 5000)),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run($this->coworker(['maxBytesPerFile' => 2048]), $this->newRun(), $this->progress());

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertStringContainsString('unreadable', $result['summary']);
        $this->assertArrayNotHasKey('huge.summary.txt', $this->written);
    }

    /**
     * These coworkers run nightly; without this, every run would re-bill the
     * whole folder.
     */
    public function testFilesWithAFresherOutputAreSkipped(): void {
        $this->written['done.summary.md'] = 'an earlier summary';
        $this->inputFolder([
            // mtime 0 so the existing output (mtime 0) is not stale.
            $this->file(11, 'done.txt', 'text/plain', 'body', 0),
            $this->file(12, 'new.txt', 'text/plain', 'body'),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertArrayHasKey('new.summary.md', $this->written);
    }

    public function testForceReprocessesFilesThatAlreadyHaveOutput(): void {
        $this->written['done.summary.md'] = 'an earlier summary';
        $this->inputFolder([$this->file(11, 'done.txt', 'text/plain', 'body', 0)]);
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run($this->coworker(['force' => true]), $this->newRun(), $this->progress());

        $this->assertSame(1, $result['itemsProcessed']);
    }

    public function testAnEmptyFolderFinishesWithoutAskingAnything(): void {
        $this->inputFolder([]);
        $this->provider->expects($this->never())->method('ask');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertArrayNotHasKey('pending', $result);
        $this->assertSame(0, $result['itemsTotal']);
    }

    // ── Resumption ────────────────────────────────────────────────────────

    /** Runs are synchronous, so there is nothing left pending to resume. */
    public function testResumeFailsBecauseRunsNeverStayPending(): void {
        $this->expectException(\RuntimeException::class);
        $this->task->resume($this->coworker(), $this->newRun(), [], $this->progress());
    }

    // ── Regressions ───────────────────────────────────────────────────────

    /**
     * A .summary.md is text and lands in the tree being walked. Left in, each
     * run feeds the last run's output back through and grows another
     * generation — a.summary.summary.md, then a.summary.summary.summary.md.
     */
    public function testOwnOutputIsNotPickedUpAsInput(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'a.summary.md', 'text/markdown', 'an earlier summary'),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertCount(1, $this->written);
    }

    /**
     * The shipped summarize template writes to /Documents/Summaries while
     * reading /Documents recursively, so the output folder is inside its own
     * input unless the walk skips it.
     */
    public function testTheConfiguredOutputFolderIsExcludedFromTheWalk(): void {
        $outputFolder = $this->createMock(Folder::class);
        $outputFolder->method('getId')->willReturn(99);
        $outputFolder->method('getDirectoryListing')->willReturn([
            $this->file(12, 'stale.md', 'text/markdown', 'output from an earlier run'),
        ]);

        $input = $this->createMock(Folder::class);
        $input->method('getDirectoryListing')->willReturn([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $outputFolder,
        ]);

        $this->userFolder->method('get')->willReturnCallback(
            fn (string $path): Folder => $path === '/Documents' ? $input : $outputFolder
        );
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run(
            $this->coworker(['outputFolder' => '/Documents/Summaries']),
            $this->newRun(),
            $this->progress()
        );

        // Without the exclusion the walk would also pick up stale.md, making
        // this two items; the single ask above is the real guard.
        $this->assertSame(1, $result['itemsTotal']);
        $this->assertSame(1, $result['itemsProcessed']);
    }

    /**
     * The walk stops at MAX_ITEMS_PER_RUN. If already-finished files are
     * filtered out only afterwards, the first 200 fill the quota every night
     * and file 201 is never reached on any run.
     */
    public function testFinishedFilesDoNotConsumeTheRunQuota(): void {
        $files = [];
        for ($i = 0; $i < 250; $i++) {
            $name = sprintf('doc%03d', $i);
            // The first 240 already have a fresh summary.
            if ($i < 240) {
                $this->written[$name . '.summary.md'] = 'done';
            }
            $files[] = $this->file(1000 + $i, $name . '.txt', 'text/plain', 'body', $i < 240 ? 0 : 100);
        }
        $this->inputFolder($files);
        $this->provider->expects($this->exactly(10))->method('ask');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        // The 10 unfinished ones must all be reached, not starved behind 200
        // finished ones.
        $this->assertSame(10, $result['itemsTotal']);
        $this->assertSame(10, $result['itemsProcessed']);
    }

    /**
     * A single binary file in the input must not stop the run, just be
     * skipped.
     */
    public function testNonTextContentIsSkippedRatherThanInlined(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'weird.txt', 'text/plain', "\xff\xfe\x00binary"),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertStringContainsString('unreadable', $result['summary']);
    }

    /** PDF bytes are not text; nothing here builds a document content block. */
    public function testPdfsAreNotCollectedByDefault(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'report.pdf', 'application/pdf', '%PDF-1.4 binary'),
        ]);
        $this->provider->expects($this->once())->method('ask');

        $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertArrayNotHasKey('report.summary.pdf', $this->written);
    }
}
