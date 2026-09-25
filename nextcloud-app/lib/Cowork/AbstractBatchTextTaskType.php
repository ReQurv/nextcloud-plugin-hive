<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Cowork;

use OCA\RequrvHive\Db\Coworker;
use OCA\RequrvHive\Db\CoworkerRun;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Base class for the "docs" task family — coworkers that walk a folder of text
 * documents and ask a model one question about each, writing the answer to a
 * file beside the source.
 *
 * Runs are synchronous: one request per file, processed in order.
 *
 * Subclasses define the per-file prompt, the output file's suffix, and the tag
 * that marks a source as done.
 */
abstract class AbstractBatchTextTaskType implements ResumableCoworkerTaskType {

    /** Hard cap on files per run, to bound cost and runtime. */
    protected const MAX_ITEMS_PER_RUN = 200;

    /** Files larger than this are skipped rather than truncated. */
    protected const DEFAULT_MAX_BYTES = 1048576;

    /**
     * Mime prefixes and exact types this family reads.
     *
     * Text only. A PDF's bytes are not text, and inlining them into a prompt
     * would send the model binary rather than a document. `application/pdf`
     * is therefore deliberately absent.
     *
     * @var list<string>
     */
    protected const DEFAULT_MIME_TYPES = ['text/', 'application/json'];

    public function __construct(
        protected readonly IRootFolder $rootFolder,
        protected readonly LLMProviderFactory $providerFactory,
        protected readonly ISystemTagManager $tagManager,
        protected readonly ISystemTagObjectMapper $tagObjectMapper,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function getFamily(): string {
        return 'docs';
    }

    /**
     * The question asked about one document.
     *
     * @param array<string, mixed> $options
     */
    abstract protected function buildPrompt(string $content, array $options): string;

    /**
     * Suffix appended to the source's base name, e.g. ".summary.md".
     *
     * @param array<string, mixed> $options
     */
    abstract protected function outputSuffix(array $options): string;

    /** System tag applied to a source once its output exists. */
    abstract protected function doneTag(): string;

    /** Verb used in the run summary, e.g. "Summarized". */
    abstract protected function summaryVerb(): string;

    public function validateOptions(array $options): void {
        if (isset($options['recursive']) && !is_bool($options['recursive'])) {
            throw new \InvalidArgumentException('recursive must be a boolean');
        }
        if (isset($options['force']) && !is_bool($options['force'])) {
            throw new \InvalidArgumentException('force must be a boolean');
        }
        if (isset($options['maxBytesPerFile'])) {
            $bytes = $options['maxBytesPerFile'];
            if (!is_int($bytes) || $bytes < 1024 || $bytes > 20 * 1048576) {
                throw new \InvalidArgumentException('maxBytesPerFile must be between 1024 and 20971520');
            }
        }
        if (isset($options['outputFolder'])) {
            $folder = $options['outputFolder'];
            if (!is_string($folder) || $folder === '' || str_contains($folder, '..')) {
                throw new \InvalidArgumentException('outputFolder must be a path without ".."');
            }
        }
        if (isset($options['mimeTypes'])) {
            if (!is_array($options['mimeTypes']) || $options['mimeTypes'] === []) {
                throw new \InvalidArgumentException('mimeTypes must be a non-empty list');
            }
            foreach ($options['mimeTypes'] as $mime) {
                if (!is_string($mime) || $mime === '') {
                    throw new \InvalidArgumentException('mimeTypes entries must be non-empty strings');
                }
            }
        }
    }

    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array {
        $userId = $coworker->getUserId();
        $options = $this->decodeOptions($coworker);
        $provider = $this->providerFactory->getProviderForUser($userId, $coworker->getProvider());

        $files = $this->collectFiles($coworker, $options);
        $total = count($files);
        $progress(0, $total);

        if ($total === 0) {
            return [
                'itemsTotal' => 0,
                'itemsProcessed' => 0,
                'summary' => 'Nothing to do — no new documents under ' . ($coworker->getInputPath() ?: '/') . '.',
            ];
        }

        return $this->runSynchronously($coworker, $provider, $files, $options, $progress);
    }

    public function resume(Coworker $coworker, CoworkerRun $run, array $state, callable $progress): array {
        // Runs are always synchronous now; nothing is ever left pending.
        throw new \RuntimeException('This task type does not leave pending runs to resume');
    }

    /**
     * One synchronous request per file.
     *
     * @param list<File> $files
     * @param array<string, mixed> $options
     * @return array{itemsTotal: int, itemsProcessed: int, summary: string}
     */
    private function runSynchronously(
        Coworker $coworker,
        LLMProviderInterface $provider,
        array $files,
        array $options,
        callable $progress,
    ): array {
        $userId = $coworker->getUserId();
        $total = count($files);
        $written = 0;
        /** @var array<string, list<string>> $failures */
        $failures = ['refused' => [], 'errored' => [], 'expired' => [], 'canceled' => [], 'unsaved' => [], 'missing' => []];
        $skipped = [];

        $progress(0, $total);

        foreach ($files as $file) {
            try {
                $content = $this->readContent($file, $options);
                if ($content === null) {
                    $skipped[] = $file->getName();
                    continue;
                }

                $result = $provider->ask($this->buildPrompt($content, $options), '', $userId);
                if (isset($result['error'])) {
                    $failures['errored'][] = $file->getName() . ' — ' . $result['error'];
                    continue;
                }

                $this->writeOutput($coworker, $file, (string)($result['response'] ?? ''), $options);
                $this->markDone($file);
                $written++;
            } catch (\Throwable $e) {
                $failures['unsaved'][] = $file->getName() . ' — ' . $e->getMessage();
                $this->logger->warning('RequrvHive Cowork docs: item failed', [
                    'coworker' => $coworker->getId(),
                    'file' => $file->getPath(),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $progress($written, $total);
            }
        }

        $label = $provider->getId();

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'summary' => $this->buildSummary($written, $total, $label, $failures, $skipped),
        ];
    }

    /**
     * @param array<string, list<string>> $failures
     * @param list<string> $skipped
     */
    private function buildSummary(int $written, int $total, string $via, array $failures, array $skipped): string {
        $summary = sprintf('%s %d/%d document(s) via %s.', $this->summaryVerb(), $written, $total, $via);

        $counts = [];
        foreach ($failures as $kind => $entries) {
            if ($entries !== []) {
                $counts[] = count($entries) . ' ' . $kind;
            }
        }
        if ($skipped !== []) {
            $counts[] = count($skipped) . ' unreadable';
        }
        if ($counts !== []) {
            $summary .= "\n" . implode(', ', $counts) . '.';
        }

        $lines = [];
        foreach ($failures as $kind => $entries) {
            foreach ($entries as $entry) {
                $lines[] = ucfirst($kind) . ': ' . $entry;
            }
        }
        foreach ($skipped as $name) {
            $lines[] = 'Unreadable: ' . $name;
        }
        if ($lines !== []) {
            $summary .= "\n" . implode("\n", array_slice($lines, 0, 50));
        }

        return $summary;
    }

    /**
     * Read a document's text, or null when it is too large or not text at all.
     *
     * Oversized files are skipped rather than truncated: half a contract
     * summarised as if it were the whole thing is worse than no summary.
     *
     * @param array<string, mixed> $options
     */
    protected function readContent(File $file, array $options): ?string {
        $limit = (int)($options['maxBytesPerFile'] ?? static::DEFAULT_MAX_BYTES);
        if ($file->getSize() > $limit) {
            return null;
        }
        try {
            $content = $file->getContent();
        } catch (\Throwable) {
            return null;
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }
        return trim($content) === '' ? null : $content;
    }

    /**
     * Write the model's answer beside the source (or into the configured
     * output folder), creating the folder if it does not exist.
     *
     * @param array<string, mixed> $options
     */
    protected function writeOutput(Coworker $coworker, File $source, string $response, array $options): void {
        $folder = $this->outputFolder($coworker, $source, $options);
        $name = $this->outputName($source, $options);

        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File) {
                $existing->putContent($response);
                return;
            }
        }
        $folder->newFile($name, $response);
    }

    /** @param array<string, mixed> $options */
    protected function outputName(File $source, array $options): string {
        $base = pathinfo($source->getName(), PATHINFO_FILENAME);
        return $base . $this->outputSuffix($options);
    }

    /** @param array<string, mixed> $options */
    protected function outputFolder(Coworker $coworker, File $source, array $options): Folder {
        $configured = (string)($options['outputFolder'] ?? $coworker->getOutputPath() ?? '');
        if ($configured === '') {
            $parent = $source->getParent();
            if (!$parent instanceof Folder) {
                throw new \RuntimeException('Source file has no parent folder');
            }
            return $parent;
        }

        $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
        try {
            $node = $userFolder->get($configured);
        } catch (NotFoundException) {
            return $userFolder->newFolder($configured);
        }
        if (!$node instanceof Folder) {
            throw new \RuntimeException("Output path $configured is not a folder");
        }
        return $node;
    }

    /**
     * Tag the source so a user can filter what has already been processed.
     * A tagging failure must not lose an output file that was written.
     */
    protected function markDone(File $file): void {
        try {
            $this->tagObjectMapper->assignTags((string)$file->getId(), 'files', [$this->resolveTag($this->doneTag())->getId()]);
        } catch (\Throwable $e) {
            $this->logger->warning('RequrvHive Cowork docs: could not tag a source file', [
                'file' => $file->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveTag(string $name): ISystemTag {
        try {
            return $this->tagManager->getTag($name, true, true);
        } catch (TagNotFoundException) {
            return $this->tagManager->createTag($name, true, true);
        }
    }

    /**
     * The documents under the coworker's input path that still need work.
     *
     * A source whose output is at least as new as itself is skipped, as is
     * anything this task itself wrote: these coworkers run on a schedule, and
     * without either check a nightly run would re-bill the whole folder and
     * then start summarising its own summaries.
     *
     * @param array<string, mixed> $options
     * @return list<File>
     */
    protected function collectFiles(Coworker $coworker, array $options): array {
        $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
        $node = $userFolder->get($coworker->getInputPath() ?: '/');

        $context = [
            'coworker' => $coworker,
            'options' => $options,
            'mimeTypes' => is_array($options['mimeTypes'] ?? null) && $options['mimeTypes'] !== []
                ? array_values(array_filter($options['mimeTypes'], 'is_string'))
                : static::DEFAULT_MIME_TYPES,
            'recursive' => (bool)($options['recursive'] ?? true),
            'force' => (bool)($options['force'] ?? false),
            'suffix' => $this->outputSuffix($options),
            'excludeFolderId' => $this->configuredOutputFolderId($coworker, $options),
        ];

        $matches = [];
        if ($node instanceof File) {
            if ($this->isCandidate($node, $context)) {
                $matches[] = $node;
            }
        } elseif ($node instanceof Folder) {
            $this->gather($node, $context, $matches);
        }

        return $matches;
    }

    /**
     * Whether one file is work this run should do.
     *
     * Three separate reasons to say no, and they have to be asked here rather
     * than after the walk, because the walk stops at MAX_ITEMS_PER_RUN: a
     * filter applied afterwards would let the first 200 already-finished files
     * fill the quota and permanently starve everything after them.
     *
     * @param array{coworker: Coworker, options: array<string, mixed>, mimeTypes: list<string>, recursive: bool, force: bool, suffix: string, excludeFolderId: int|null} $context
     */
    private function isCandidate(File $file, array $context): bool {
        if (!$this->matches($file, $context['mimeTypes'])) {
            return false;
        }
        // Our own output is text too, and it lands in the tree we are walking.
        // Left in, each run would feed the previous run's output back through
        // and grow another generation of a.summary.summary.md every night.
        if ($context['suffix'] !== '' && str_ends_with($file->getName(), $context['suffix'])) {
            return false;
        }
        if ($context['force']) {
            return true;
        }
        return !$this->alreadyDone($context['coworker'], $file, $context['options']);
    }

    /** @param list<string> $mimeTypes */
    private function matches(File $file, array $mimeTypes): bool {
        $mime = $file->getMimetype();
        foreach ($mimeTypes as $candidate) {
            // A trailing slash means a family ("text/"); anything else is exact.
            if (str_ends_with($candidate, '/') ? str_starts_with($mime, $candidate) : $mime === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * The configured output folder's id, so the walk can skip it.
     *
     * The shipped summarize template writes to /Documents/Summaries while
     * reading /Documents recursively, so without this the output folder is
     * part of its own input.
     *
     * @param array<string, mixed> $options
     */
    private function configuredOutputFolderId(Coworker $coworker, array $options): ?int {
        $configured = (string)($options['outputFolder'] ?? $coworker->getOutputPath() ?? '');
        if ($configured === '') {
            return null;
        }
        try {
            $node = $this->rootFolder->getUserFolder($coworker->getUserId())->get($configured);
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof Folder ? $node->getId() : null;
    }

    /**
     * @param array{coworker: Coworker, options: array<string, mixed>, mimeTypes: list<string>, recursive: bool, force: bool, suffix: string, excludeFolderId: int|null} $context
     * @param list<File> $matches
     */
    private function gather(Folder $folder, array $context, array &$matches): void {
        foreach ($folder->getDirectoryListing() as $child) {
            if (count($matches) >= static::MAX_ITEMS_PER_RUN) {
                return;
            }
            if ($child instanceof File) {
                if ($this->isCandidate($child, $context)) {
                    $matches[] = $child;
                }
            } elseif ($context['recursive'] && $child instanceof Folder) {
                if ($context['excludeFolderId'] !== null && $child->getId() === $context['excludeFolderId']) {
                    continue;
                }
                $this->gather($child, $context, $matches);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function alreadyDone(Coworker $coworker, File $file, array $options): bool {
        try {
            $folder = $this->outputFolder($coworker, $file, $options);
            $name = $this->outputName($file, $options);
            if (!$folder->nodeExists($name)) {
                return false;
            }
            $existing = $folder->get($name);
            // The source is what the output describes, so an output older than
            // its source is stale and the file is worth doing again.
            return $existing->getMTime() >= $file->getMTime();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    protected function decodeOptions(Coworker $coworker): array {
        $raw = $coworker->getOptions();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
