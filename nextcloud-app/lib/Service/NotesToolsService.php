<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The built-in chat tools for the user's notes.
 *
 * The notes app stores a note as a plain text file in the user's `Notes`
 * folder: the first line is the title, subfolders are categories. This
 * service works on those files through the files API, so it sees exactly
 * what the Notes app sees, including notes the app has not indexed yet.
 */
class NotesToolsService {
    public const TOOL_LIST_NOTES = 'list_notes';
    public const TOOL_GET_NOTE = 'get_note';
    public const TOOL_CREATE_NOTE = 'create_note';

    public const NOTES_DIR = 'Notes';

    private const MAX_NOTE_CHARS = 256 * 1024;
    private const SNIPPET_CHARS = 200;

    public function __construct(
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    public static function isTool(string $name): bool {
        return match ($name) {
            self::TOOL_LIST_NOTES,
            self::TOOL_GET_NOTE,
            self::TOOL_CREATE_NOTE => true,
            default => false,
        };
    }

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getTools(): array {
        $notePathProp = [
            'type' => 'string',
            'description' => "Path of the note relative to the Notes folder, e.g. 'Ideas' for a top-level note or 'Ideas/groceries' for one in a category. As shown by list_notes.",
        ];

        return [
            [
                'name' => self::TOOL_LIST_NOTES,
                'description' => "List the user's notes (title, category, a short snippet and when they were last edited). Use it before reading or creating notes to know what exists.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
            [
                'name' => self::TOOL_GET_NOTE,
                'description' => "Read the full text of one of the user's notes. Use it whenever a question can be answered from a note the user has but has not attached to the chat. The path comes from list_notes.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $notePathProp,
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => self::TOOL_CREATE_NOTE,
                'description' => "Create a new note for the user (title plus text, optionally inside a category). Use it when the user asks to write down, remember or save something as a note. Fails instead of overwriting when a note with the same name already exists.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Note title.',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Note text (plain text or markdown). May be empty for a title-only note.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Optional category (a subfolder of the Notes folder), created when missing.',
                        ],
                    ],
                    'required' => ['title', 'content'],
                ],
            ],
        ];
    }

    /**
     * Execute one of the notes tools.
     *
     * Never throws: a failure is returned as an isError result so the model
     * can react to it inside the same turn.
     *
     * @param array<string, mixed> $input
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function execute(string $name, array $input, string $userId): array {
        try {
            $result = match ($name) {
                self::TOOL_LIST_NOTES => $this->listNotes($userId),
                self::TOOL_GET_NOTE => $this->getNote((string)($input['path'] ?? ''), $userId),
                self::TOOL_CREATE_NOTE => $this->createNote(
                    (string)($input['title'] ?? ''),
                    (string)($input['content'] ?? ''),
                    (string)($input['category'] ?? ''),
                    $userId,
                ),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('RequrvHive notes tool failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            if ($e instanceof NotFoundException) {
                return $this->errorResult("Not found: the note '{$this->userNotePath((string)($input['path'] ?? ''))}' does not exist.");
            }
            return $this->errorResult('The notes tool call failed. Check the parameters and try again.');
        }

        return $result;
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listNotes(string $userId): array {
        $folder = $this->notesFolder($userId);
        if ($folder === null) {
            return $this->textResult("The user has no notes yet. New ones can be created with the create_note tool.");
        }
        $notes = $this->collectNotes($folder, '');

        if ($notes === []) {
            return $this->textResult("The user has no notes yet. New ones can be created with the create_note tool.");
        }

        usort($notes, static fn(array $a, array $b): int => strcasecmp($a['path'], $b['path']));

        $lines = ['Notes (path — title, last edited):'];
        foreach ($notes as $note) {
            $lines[] = '- ' . $note['path'] . ' — ' . $note['title']
                . '  (edited ' . date('Y-m-d', (int)$note['mtime'])
                . ($note['snippet'] !== '' ? ": " . $note['snippet'] : '') . ')';
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function getNote(string $path, string $userId): array {
        $folder = $this->notesFolder($userId);
        if ($folder === null) {
            throw new NotFoundException(self::NOTES_DIR);
        }
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return $this->errorResult('The note path is empty. Pass e.g. "Ideas/my note" (see list_notes).');
        }
        // Folder::get() throws NotFoundException for missing paths, so probe
        // with nodeExists() first.
        $note = null;
        if ($folder->nodeExists($normalized)) {
            $candidate = $folder->get($normalized);
            if ($candidate instanceof File) {
                $note = $candidate;
            }
        }
        if ($note === null) {
            // The Notes app shows notes by title, without the file extension
            // (create_note stores "my note" as "my note.txt"). Resolve a bare
            // title the same way.
            foreach (['txt', 'md', 'markdown', 'org', 'note'] as $ext) {
                $candidatePath = $normalized . '.' . $ext;
                if ($folder->nodeExists($candidatePath)) {
                    $candidate = $folder->get($candidatePath);
                    if ($candidate instanceof File) {
                        $note = $candidate;
                        break;
                    }
                }
            }
        }
        if ($note === null) {
            return $this->errorResult("The note '{$path}' is not a file.");
        }

        $content = (string)$note->getContent();
        if (mb_strlen($content) > self::MAX_NOTE_CHARS) {
            $content = mb_substr($content, 0, self::MAX_NOTE_CHARS) . "\n\n[note truncated at " . self::MAX_NOTE_CHARS . ' characters]';
        }

        return $this->textResult("Note '" . $this->userNotePath($path) . "' (" . date('Y-m-d', (int)$note->getMTime()) . "):\n\n" . $content);
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function createNote(string $title, string $content, string $category, string $userId): array {
        $title = trim($title);
        if ($title === '') {
            return $this->errorResult('A note needs a title.');
        }

        $folder = $this->notesFolder($userId) ?? $this->rootFolder->getUserFolder($userId)->newFolder(self::NOTES_DIR);
        if ($category !== '') {
            $folder = $this->ensureCategory($folder, $category);
        }

        $fileName = $this->sanitizeFileName($title);
        if ($fileName === '') {
            return $this->errorResult('The title cannot be used as a file name; try a different one.');
        }

        if ($folder->nodeExists($fileName)) {
            return $this->errorResult("A note named '{$title}' already exists in that folder. Pick a different title or read it with get_note.");
        }
        $file = $folder->newFile($fileName);
        $file->putContent($title . "\n" . ltrim($content, "\n"));

        return $this->textResult("Note '" . $this->userNotePath(($category !== '' ? $category . '/' : '') . $fileName) . "' created. Read it back with get_note if you need to check it.");
    }

    /**
     * Walk the Notes folder (categories are one level of subfolders) and
     * collect title, mtime and a snippet per note.
     *
     * @return list<array{path: string, title: string, mtime: int, snippet: string}>
     */
    private function collectNotes(Folder $folder, string $prefix): array {
        $notes = [];
        foreach ($folder->getDirectoryListing() as $node) {
            $name = $node->getName();
            $path = $prefix === '' ? $name : $prefix . '/' . $name;

            if ($node instanceof Folder) {
                foreach ($node->getDirectoryListing() as $child) {
                    if ($child instanceof File) {
                        $notes[] = $this->describeNote($child, $path . '/' . $child->getName());
                    }
                }
                continue;
            }

            if ($node instanceof File) {
                $notes[] = $this->describeNote($node, $path);
            }
        }

        return $notes;
    }

    /**
     * @return array{path: string, title: string, mtime: int, snippet: string}
     */
    private function describeNote(File $file, string $path): array {
        $title = $file->getName();
        $snippet = '';

        try {
            if ($file->getSize() <= 64 * 1024) {
                $content = (string)$file->getContent();
                $firstLine = trim((string)explode("\n", $content, 2)[0]);
                if ($firstLine !== '') {
                    $title = $firstLine;
                }
                $rest = trim(explode("\n", $content, 2)[1] ?? '');
                if ($rest !== '') {
                    $snippet = mb_strimwidth($rest, 0, self::SNIPPET_CHARS, '…');
                }
            }
        } catch (\Throwable) {
            // An unreadable note is still listed, just without a snippet.
        }

        return [
            'path' => $path,
            'title' => $title,
            'mtime' => (int)$file->getMTime(),
            'snippet' => $snippet,
        ];
    }

    private function notesFolder(string $userId): ?Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        if (!$userFolder->nodeExists(self::NOTES_DIR)) {
            return null;
        }

        $folder = $userFolder->get(self::NOTES_DIR);
        if (!$folder instanceof Folder) {
            throw new \RuntimeException("The '" . self::NOTES_DIR . "' entry is not a folder.");
        }

        return $folder;
    }

    private function ensureCategory(Folder $notesFolder, string $category): Folder {
        $parts = array_values(array_filter(array_map('trim', explode('/', $category)), static fn(string $p): bool => $p !== '' && $p !== '.'));
        if ($parts === []) {
            return $notesFolder;
        }
        if (in_array('..', $parts, true)) {
            throw new \InvalidArgumentException('Categories cannot use "..".');
        }

        $folder = $notesFolder;
        $path = '';
        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            if (!$folder->nodeExists($part)) {
                $folder = $folder->newFolder($part);
            } else {
                $child = $folder->get($part);
                if (!$child instanceof Folder) {
                    throw new \RuntimeException("Category '{$path}' is not a folder.");
                }
                $folder = $child;
            }
        }

        return $folder;
    }

    private function normalizePath(string $path): string {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return (string)preg_replace('/\/{2,}/', '/', $path);
    }

    private function userNotePath(string $path): string {
        $normalized = $this->normalizePath($path);
        return self::NOTES_DIR . '/' . ($normalized === '' ? '' : $normalized);
    }

    private function sanitizeFileName(string $title): string {
        $name = str_replace(['/', '\\', "\0"], ' ', $title);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = mb_substr($name, 0, 180);
        $name = trim($name, " .");
        // The Notes app only shows files carrying a note extension (txt, org,
        // markdown, md, note — or the user's custom suffix). A title without
        // one gets the app's default ".txt" so the note appears in the app.
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'org', 'markdown', 'md', 'note'], true)) {
            $name .= '.txt';
        }
        return $name;
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
