<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCA\RequrvHive\Service\Exception\ContentTooLargeException;
use OCP\App\IAppManager;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The built-in agentic tools of the chat.
 *
 * Where McpClientService forwards model-initiated tool calls to external MCP
 * servers a user configured, this service executes the tools the bot offers
 * out of the box: the file tools (list a directory, read a file, look one up
 * and view an image) plus the calendar, mail and notes tools that the
 * domain services implement. A question the user's own data can answer does
 * not depend on an attachment or on any MCP server being configured.
 *
 * Tool definitions are in the app's canonical Anthropic shape
 * ({name, description, input_schema}) and results in the same
 * `['content' => [...], 'isError' => bool]` shape the MCP path returns, so the
 * provider's tool loop handles both sources identically. A read_image result
 * carries an extra image block; providers with vision render it, text-only
 * providers drop it and keep the metadata.
 *
 * The file tools run against the caller's user folder; the calendar, mail and
 * notes tools are scoped to the caller by the domain services, so the model
 * can only ever see what that user can see. The calendar, mail and notes
 * groups are offered only while the app that backs each of them is enabled.
 */
class ChatToolsService {
    public const TOOL_LIST_DIRECTORY = 'list_directory';
    public const TOOL_GET_FILE_INFO = 'get_file_info';
    public const TOOL_READ_FILE = 'read_file';
    public const TOOL_SEARCH_FILES = 'search_files';
    public const TOOL_READ_IMAGE = 'read_image';

    // Calendar, mail and notes tools are offered only while the app that
    // backs them is enabled for the instance; the names themselves are fixed.
    public const TOOL_LIST_CALENDARS = 'list_calendars';
    public const TOOL_LIST_CALENDAR_EVENTS = 'list_calendar_events';
    public const TOOL_CREATE_CALENDAR_EVENT = 'create_calendar_event';
    public const TOOL_LIST_MAILBOXES = 'list_mailboxes';
    public const TOOL_LIST_MAIL_MESSAGES = 'list_mail_messages';
    public const TOOL_GET_MAIL_MESSAGE = 'get_mail_message';
    public const TOOL_LIST_NOTES = 'list_notes';
    public const TOOL_GET_NOTE = 'get_note';
    public const TOOL_CREATE_NOTE = 'create_note';

    /** Default and hard cap for read_file: how much of one file the model gets. */
    private const DEFAULT_READ_BYTES = 256 * 1024;
    private const MAX_READ_BYTES = 1024 * 1024;
    private const MIN_READ_BYTES = 1024;

    private const MAX_SEARCH_RESULTS = 50;
    private const MAX_LISTING_NODES = 400;
    private const MAX_LISTING_DEPTH = 4;

    public function __construct(
        private FileService $files,
        private ImageOptimizer $images,
        private CalendarToolsService $calendar,
        private MailToolsService $mail,
        private NotesToolsService $notes,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
    }

    public static function isBuiltin(string $name): bool {
        return match ($name) {
            self::TOOL_LIST_DIRECTORY,
            self::TOOL_GET_FILE_INFO,
            self::TOOL_READ_FILE,
            self::TOOL_SEARCH_FILES,
            self::TOOL_READ_IMAGE,
            self::TOOL_LIST_CALENDARS,
            self::TOOL_LIST_CALENDAR_EVENTS,
            self::TOOL_CREATE_CALENDAR_EVENT,
            self::TOOL_LIST_MAILBOXES,
            self::TOOL_LIST_MAIL_MESSAGES,
            self::TOOL_GET_MAIL_MESSAGE,
            self::TOOL_LIST_NOTES,
            self::TOOL_GET_NOTE,
            self::TOOL_CREATE_NOTE => true,
            default => false,
        };
    }

    /**
     * The tool definitions to offer the model, in the app's canonical shape.
     *
     * The descriptions carry the agentic policy: use the tools whenever the
     * question the user asked can plausibly be answered from their files
     * rather than guessing or asking them to attach something.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getTools(): array {
        $pathProp = [
            'type' => 'string',
            'description' => "Path relative to the root of the user's files. '/' or '' is the root, e.g. 'Documents' or 'Documents/invoices'.",
        ];

        $tools = [
            [
                'name' => self::TOOL_LIST_DIRECTORY,
                'description' => "List the contents of a directory in the user's Nextcloud files, with type, size and modified date per entry. Use it to inspect what is inside a folder before reading anything. Paths are relative to the root of the user's files ('/' is the root).",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $pathProp,
                        'recursive' => [
                            'type' => 'boolean',
                            'description' => 'Also list the contents of subdirectories as a tree. Defaults to false.',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => self::TOOL_GET_FILE_INFO,
                'description' => "Get metadata for a single file or folder in the user's Nextcloud files: name, type, size, MIME type and modified date. Use it when the user asks about a file without asking for its content.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $pathProp,
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => self::TOOL_READ_FILE,
                'description' => "Read the content of a text file from the user's Nextcloud files (code, documents, notes, CSV, JSON, ...). Use it whenever a question can be answered from a file the user has but has not attached to the chat. For images use read_image instead.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $pathProp,
                        'max_bytes' => [
                            'type' => 'integer',
                            'minimum' => self::MIN_READ_BYTES,
                            'maximum' => self::MAX_READ_BYTES,
                            'description' => 'Maximum number of bytes to return (default 262144). Larger files are truncated with a notice.',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => self::TOOL_SEARCH_FILES,
                'description' => "Search the user's Nextcloud files by name (case-insensitive), optionally restricted to a subfolder. Use it to locate a file the user mentions but whose path you do not know yet.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Name pattern to look for.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Restrict the search to this directory (default: the whole file root).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => self::TOOL_READ_IMAGE,
                'description' => "Load an image from the user's Nextcloud files (JPEG, PNG, GIF or WebP) so it can be analyzed visually. Use it when the user asks about a picture they have in their files but did not attach to the chat.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $pathProp,
                    ],
                    'required' => ['path'],
                ],
            ],
        ];

        // The calendar, mail and notes tools are only useful while the app
        // that backs them is enabled on this instance. Hiding them otherwise
        // keeps the tool list short and never offers the model a dead end.
        if ($this->appManager->isEnabledForAnyone('calendar')) {
            $tools = [...$tools, ...$this->calendar->getTools()];
        }
        if ($this->appManager->isEnabledForAnyone('mail')) {
            $tools = [...$tools, ...$this->mail->getTools()];
        }
        if ($this->appManager->isEnabledForAnyone('notes')) {
            $tools = [...$tools, ...$this->notes->getTools()];
        }

        return $tools;
    }

    /**
     * Execute one of the built-in tools.
     *
     * Never throws: a failure (missing file, unreadable content, unknown tool)
     * is returned as an isError result so the model can react to it inside the
     * same turn instead of aborting the whole reply.
     *
     * @param array<string, mixed> $input
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function execute(string $name, array $input, string $userId): array {
        try {
            // The domain services handle their own errors and never throw.
            $result = match (true) {
                $name === self::TOOL_LIST_DIRECTORY => $this->listDirectory((string)($input['path'] ?? ''), (bool)($input['recursive'] ?? false), $userId),
                $name === self::TOOL_GET_FILE_INFO => $this->getFileInfo((string)($input['path'] ?? ''), $userId),
                $name === self::TOOL_READ_FILE => $this->readFile((string)($input['path'] ?? ''), isset($input['max_bytes']) ? (int)$input['max_bytes'] : null, $userId),
                $name === self::TOOL_SEARCH_FILES => $this->searchFiles((string)($input['query'] ?? ''), (string)($input['path'] ?? '/'), $userId),
                $name === self::TOOL_READ_IMAGE => $this->readImage((string)($input['path'] ?? ''), $userId),
                CalendarToolsService::isTool($name) => $this->calendar->execute($name, $input, $userId),
                MailToolsService::isTool($name) => $this->mail->execute($name, $input, $userId),
                NotesToolsService::isTool($name) => $this->notes->execute($name, $input, $userId),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('RequrvHive chat tool failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            // Only a generic failure is reported back: an exception message can
            // carry internal server paths, and this text reaches the model.
            $message = match (true) {
                $e instanceof NotFoundException => "Not found: the file or folder '{$this->userPath((string)($input['path'] ?? ''))}' does not exist.",
                $e instanceof ContentTooLargeException => 'File too large to read: ' . $e->getMessage(),
                default => 'The tool call failed. Check the path and try again.',
            };
            return $this->errorResult($message);
        }

        return $result;
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listDirectory(string $path, bool $recursive, string $userId): array {
        $lines = [];
        $this->walk($path, $recursive, 0, $lines, $userId);

        if ($lines === []) {
            $text = "Directory '{$this->userPath($path)}' is empty.";
        } else {
            $text = "Directory '{$this->userPath($path)}':" . "\n" . implode("\n", $lines);
        }

        return $this->textResult($text);
    }

    /**
     * Recursively render the directory tree. $relPath is the child's path
     * relative to the root, so the lines carry paths the model can pass back
     * to the other tools as-is.
     *
     * @param list<string> $lines
     */
    private function walk(string $path, bool $recursive, int $depth, array &$lines, string $userId): void {
        $normalized = $this->normalize($path);
        $listing = $this->files->listDirectory($normalized, $userId);

        $items = $listing['items'];
        // Folders first, then files, each alphabetical — the model reads the
        // listing top to bottom, so a stable, predictable order matters.
        usort($items, static fn(array $a, array $b): int =>
            ((string)$a['type'] === 'folder' ? 0 : 1) <=> ((string)$b['type'] === 'folder' ? 0 : 1)
            ?: strcasecmp((string)$a['name'], (string)$b['name']));

        foreach ($items as $item) {
            if (count($lines) >= self::MAX_LISTING_NODES) {
                $lines[] = '(listing truncated at ' . self::MAX_LISTING_NODES . ' entries)';
                return;
            }

            $name = (string)$item['name'];
            $childPath = $normalized === '' ? $name : $normalized . '/' . $name;
            $indent = str_repeat('  ', $depth);

            if ($item['type'] === 'folder') {
                $lines[] = $indent . $name . "/  (directory — " . $childPath . ')';
                if ($recursive && $depth + 1 < self::MAX_LISTING_DEPTH) {
                    $this->walk($childPath, $recursive, $depth + 1, $lines, $userId);
                }
                continue;
            }

            $lines[] = $indent . $name . '  (' . $this->humanSize((int)$item['size'])
                . ', ' . $item['mimeType']
                . ', modified ' . date('Y-m-d', (int)$item['mtime'])
                . " — " . $childPath . ')';
        }
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function getFileInfo(string $path, string $userId): array {
        $info = $this->files->getInfo($this->normalize($path), $userId);

        $lines = [
            'name: ' . $info['name'],
            'type: ' . $info['type'],
            'path: ' . ($path === '' ? '/' : $path),
            'size: ' . $this->humanSize((int)$info['size']),
            'mime: ' . $info['mimeType'],
            'modified: ' . date('Y-m-d H:i', (int)$info['mtime']),
        ];
        if (!empty($info['isEncrypted'])) {
            $lines[] = 'encrypted: yes';
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function readFile(string $path, ?int $maxBytes, string $userId): array {
        $data = $this->files->getContent($this->normalize($path), $userId);

        if ($data['encoding'] !== 'text') {
            $hint = str_starts_with($data['mimeType'], 'image/')
                ? " This looks like an image; use the read_image tool instead."
                : '';
            return $this->errorResult("The file is not text ({$data['mimeType']}), so it cannot be read as content.{$hint}");
        }

        $cap = $maxBytes === null ? self::DEFAULT_READ_BYTES : min(max($maxBytes, self::MIN_READ_BYTES), self::MAX_READ_BYTES);
        $content = $data['content'];
        $truncated = mb_strlen($content) > $cap;
        if ($truncated) {
            $content = mb_substr($content, 0, $cap);
        }

        $header = "--- {$data['name']} ({$data['mimeType']}, {$data['size']} bytes" . ($truncated ? ", showing first {$cap} characters" : '') . ") ---\n";
        $text = $truncated
            ? $header . $content . "\n\n[content truncated — request a smaller range or search for a specific part]"
            : $header . $content;

        return $this->textResult($text);
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function searchFiles(string $query, string $path, string $userId): array {
        if (trim($query) === '') {
            return $this->errorResult('An empty query matches everything; give a file name or part of one to search for.');
        }

        $result = $this->files->search($query, $userId, null, $this->normalize($path) === '' ? '/' : $this->normalize($path));

        if ($result['results'] === []) {
            return $this->textResult("No files matching '{$query}' found under '{$this->userPath($path)}'.");
        }

        $lines = ["Files matching '{$query}' (showing up to " . self::MAX_SEARCH_RESULTS . "):"];
        foreach (array_slice($result['results'], 0, self::MAX_SEARCH_RESULTS) as $node) {
            $lines[] = '- ' . $node['name'] . '  (' . $this->humanSize((int)$node['size'])
                . ', ' . $node['mimeType']
                . ', modified ' . date('Y-m-d', (int)$node['mtime']) . ')';
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * Load an image so a vision-capable provider can see it. The result carries
     * the metadata as text plus one image block; a text-only provider drops
     * the image block and the model still gets the metadata.
     *
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function readImage(string $path, string $userId): array {
        $data = $this->files->getContent($this->normalize($path), $userId);

        if (!str_starts_with($data['mimeType'], 'image/')) {
            return $this->errorResult("The file is not an image ({$data['mimeType']}); use read_file for text.");
        }
        if (!$this->images->isSupported($data['mimeType'])) {
            return $this->errorResult("The image format ({$data['mimeType']}) cannot be analyzed; only JPEG, PNG, GIF and WebP are supported.");
        }

        $raw = base64_decode($data['content'], true);
        if ($raw === false) {
            return $this->errorResult('The image data could not be decoded.');
        }

        $prepared = $this->images->prepare($raw, $data['mimeType']);

        $text = "Image '{$data['name']}' ({$prepared['mimeType']}, {$data['size']} bytes) loaded for visual analysis.";
        $content = [
            ['type' => 'text', 'text' => $text],
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $prepared['mimeType'],
                    'data' => $prepared['base64'],
                ],
            ],
        ];

        return ['content' => $content, 'isError' => false];
    }

    private function normalize(string $path): string {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        // Collapse duplicate separators left over from model-built paths.
        return (string)preg_replace('/\/{2,}/', '/', $path);
    }

    private function userPath(string $path): string {
        $normalized = $this->normalize($path);
        return $normalized === '' ? '/' : $normalized;
    }

    private function humanSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $index = 0;
        while ($value >= 1024 && $index < 3) {
            $value = intdiv($value, 1024);
            $index++;
        }
        return $value . ' ' . $units[$index];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function textResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function errorResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }
}
