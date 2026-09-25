<?php

namespace OCA\RequrvHive\Tests\Unit\Controller;

use OCA\RequrvHive\Controller\ConversationController;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\Message as MessageEntity;
use OCA\RequrvHive\Db\MessageFile;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\ProjectMapper;
use OCA\RequrvHive\Db\ProjectPathMapper;
use OCA\RequrvHive\Service\ChatToolsService;
use OCA\RequrvHive\Service\ContextChatService;
use OCA\RequrvHive\Service\FileService;
use OCA\RequrvHive\Service\ImageOptimizer;
use OCA\RequrvHive\Service\McpClientService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers directory expansion in ConversationController::buildFileContentBlocks().
 *
 * Attaching a directory must make every file inside it available to the model:
 * the message carries a tree of the whole directory plus the content of each
 * readable file, so the bot can answer about a nested file without the user
 * re-attaching it with /add-file. What matters here is that the expansion is
 * complete (nested files included, relative paths in the headers) and bounded
 * (caps keep a big tree out of the context window).
 */
class ConversationDirectoryContextTest extends TestCase {
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private MessageMapper $messageMapper;
    private MessageFileMapper $messageFileMapper;

    private function makeController(): ConversationController {
        $this->fileService = $this->createMock(FileService::class);
        $this->imageOptimizer = $this->createMock(ImageOptimizer::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->messageFileMapper = $this->createMock(MessageFileMapper::class);

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'requrvhive',
            $request,
            $this->createMock(ConversationMapper::class),
            $this->messageMapper,
            $this->messageFileMapper,
            $this->createMock(ProjectMapper::class),
            $this->createMock(ProjectPathMapper::class),
            $this->createMock(LLMProviderFactory::class),
            $this->fileService,
            $this->imageOptimizer,
            $this->createMock(McpClientService::class),
            $this->createMock(ChatToolsService::class),
            $this->createMock(IJobList::class),
            $this->createMock(ContextChatService::class),
            'testuser',
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array{blocks: list<array<string, mixed>>, documents: list<array<string, mixed>>} */
    private function buildBlocks(ConversationController $controller, array $paths): array {
        $method = new \ReflectionMethod(ConversationController::class, 'buildFileContentBlocks');
        return $method->invoke($controller, $paths);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function blockTexts(array $blocks): array {
        return array_values(array_map(
            static fn(array $b): string => (string)($b['text'] ?? ''),
            array_filter($blocks, static fn(array $b): bool => ($b['type'] ?? '') === 'text')
        ));
    }

    /** A file node for listDirectory() items. */
    private static function item(string $name, string $type, int $size = 0, string $mimeType = 'text/plain'): array {
        return [
            'name' => $name,
            'path' => '/' . $name,
            'size' => $size,
            'mimeType' => $mimeType,
            'mtime' => 0,
            'etag' => '',
            'permissions' => 31,
            'type' => $type,
            'isEncrypted' => false,
            'id' => 0,
        ];
    }

    private static function textContent(string $name, string $content, string $mimeType = 'text/plain'): array {
        return [
            'name' => $name,
            'mimeType' => $mimeType,
            'size' => strlen($content),
            'encoding' => 'text',
            'content' => $content,
        ];
    }

    public function testDirectoryExpandsToItsFilesIncludingNestedOnes(): void {
        $controller = $this->makeController();

        $this->fileService->method('getInfo')->willReturnCallback(
            static function (string $path): array {
                return match ($path) {
                    '/docs' => self::item('docs', 'folder', 0, 'httpd/unix-directory'),
                    default => throw new NotFoundException($path),
                };
            }
        );
        $this->fileService->method('listDirectory')->willReturnCallback(
            static function (string $path): array {
                return match ($path) {
                    '/docs' => [
                        'path' => $path,
                        'count' => 3,
                        'items' => [
                            self::item('a.md', 'file', 3, 'text/markdown'),
                            self::item('data.zip', 'file', 10, 'application/zip'),
                            self::item('sub', 'folder', 0, 'httpd/unix-directory'),
                        ],
                    ],
                    '/docs/sub' => [
                        'path' => $path,
                        'count' => 1,
                        'items' => [self::item('b.txt', 'file', 3)],
                    ],
                    default => throw new NotFoundException($path),
                };
            }
        );
        $this->fileService->method('getContent')->willReturnCallback(
            static function (string $path): array {
                return match ($path) {
                    '/docs/a.md' => self::textContent('a.md', 'AAA', 'text/markdown'),
                    '/docs/sub/b.txt' => self::textContent('b.txt', 'BBB'),
                    default => throw new NotFoundException($path),
                };
            }
        );

        $result = $this->buildBlocks($controller, ['/docs']);
        $blocks = $result['blocks'];

        // One header block plus one block per readable file.
        $this->assertCount(3, $blocks);
        $this->assertSame([], $result['documents']);

        $this->assertStringContainsString('--- Directory: docs (3 files, 2 included as content) ---', $blocks[0]['text']);
        // The tree shows every entry, nested ones indented, binaries included.
        $this->assertStringContainsString("a.md (3 bytes)\n", $blocks[0]['text']);
        $this->assertStringContainsString("data.zip (10 bytes)\n", $blocks[0]['text']);
        $this->assertStringContainsString("sub/\n  b.txt (3 bytes)", $blocks[0]['text']);
        // The binary is named in the "not read" note instead of being shipped as base64.
        $this->assertStringContainsString('Not read: data.zip', $blocks[0]['text']);

        // The nested file is available under its relative path — the exact
        // case where the bot used to demand a separate /add-file.
        $this->assertContains("--- File: a.md (text/markdown, 3 bytes) ---\nAAA", $this->blockTexts($blocks));
        $this->assertContains("--- File: sub/b.txt (text/plain, 3 bytes) ---\nBBB", $this->blockTexts($blocks));
    }

    public function testFilesBeyondTheCapStayInTheTreeButAreNotRead(): void {
        $controller = $this->makeController();

        $cap = (new \ReflectionClass(ConversationController::class))->getConstant('MAX_DIRECTORY_FILES');
        $names = [];
        for ($i = 0; $i <= $cap; $i++) {
            $names[] = 'f' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.txt';
        }

        $this->fileService->method('getInfo')->willReturn(self::item('many', 'folder', 0, 'httpd/unix-directory'));
        $this->fileService->method('listDirectory')->willReturn([
            'path' => '/many',
            'count' => count($names),
            'items' => array_map(
                fn(string $n): array => self::item($n, 'file', 1),
                $names
            ),
        ]);
        $this->fileService->method('getContent')->willReturnCallback(
            static fn(string $path): array => self::textContent(basename($path), 'x')
        );

        $result = $this->buildBlocks($controller, ['/many']);
        $blocks = $result['blocks'];

        // Header + one block per included file.
        $this->assertCount($cap + 1, $blocks);
        // Every file stays in the tree…
        $this->assertStringContainsString('f100.txt (1 bytes)', $blocks[0]['text']);
        // …and the overflow is named in the note without a content block.
        $this->assertStringContainsString('Not read: f100.txt', $blocks[0]['text']);
        $texts = $this->blockTexts($blocks);
        $this->assertContains("--- File: f000.txt (text/plain, 1 bytes) ---\nx", $texts);
        foreach ($texts as $text) {
            $this->assertStringStartsNotWith('--- File: f100.txt', $text);
        }
    }

    public function testTextBudgetCapsTheCombinedDirectoryContent(): void {
        $controller = $this->makeController();

        $big = str_repeat('x', 600 * 1024);
        $this->fileService->method('getInfo')->willReturn(self::item('big', 'folder', 0, 'httpd/unix-directory'));
        $this->fileService->method('listDirectory')->willReturn([
            'path' => '/big',
            'count' => 2,
            'items' => [
                self::item('big1.txt', 'file', strlen($big)),
                self::item('big2.txt', 'file', strlen($big)),
            ],
        ]);
        $this->fileService->method('getContent')->willReturnCallback(
            static function (string $path) use ($big): array {
                return self::textContent(basename($path), $big);
            }
        );

        $result = $this->buildBlocks($controller, ['/big']);
        $blocks = $result['blocks'];

        $this->assertCount(2, $blocks);
        $this->assertStringContainsString('--- Directory: big (2 files, 1 included as content) ---', $blocks[0]['text']);
        $this->assertStringContainsString('Not read: big2.txt', $blocks[0]['text']);
        $this->assertStringNotContainsString('--- File: big2.txt', implode('', $this->blockTexts($blocks)));
        $this->assertStringContainsString('--- File: big1.txt (text/plain, ' . strlen($big) . ' bytes) ---', $blocks[1]['text']);
    }

    public function testPdfAndImageFilesBecomeDocumentAndImageBlocks(): void {
        $controller = $this->makeController();

        $this->fileService->method('getInfo')->willReturn(self::item('mixed', 'folder', 0, 'httpd/unix-directory'));
        $this->fileService->method('listDirectory')->willReturn([
            'path' => '/mixed',
            'count' => 3,
            'items' => [
                self::item('note.txt', 'file', 2),
                self::item('photo.jpg', 'file', 100, 'image/jpeg'),
                self::item('report.pdf', 'file', 50, 'application/pdf'),
            ],
        ]);
        $this->fileService->method('getContent')->willReturnCallback(
            static function (string $path): array {
                return match ($path) {
                    '/mixed/note.txt' => self::textContent('note.txt', 'hi'),
                    '/mixed/photo.jpg' => [
                        'name' => 'photo.jpg',
                        'mimeType' => 'image/jpeg',
                        'size' => 100,
                        'encoding' => 'base64',
                        'content' => base64_encode('rawimage'),
                    ],
                    '/mixed/report.pdf' => [
                        'name' => 'report.pdf',
                        'mimeType' => 'application/pdf',
                        'size' => 50,
                        'encoding' => 'base64',
                        'content' => base64_encode('%PDF-1.4'),
                    ],
                    default => throw new NotFoundException($path),
                };
            }
        );
        $this->imageOptimizer->method('isSupported')->willReturn(true);
        $this->imageOptimizer->method('optimize')->willReturn(['mimeType' => 'image/jpeg', 'data' => 'AAABBB']);

        $result = $this->buildBlocks($controller, ['/mixed']);
        $blocks = $result['blocks'];

        $types = array_map(static fn(array $b): string => (string)$b['type'], $blocks);
        $this->assertSame(['text', 'text', 'image', 'document'], $types);

        // Citations must resolve: the documents index mirrors the document block.
        $this->assertCount(1, $result['documents']);
        $document = $result['documents'][0];
        $this->assertSame('/mixed/report.pdf', $document['path']);
        $this->assertSame('report.pdf', $document['title']);
        $this->assertSame('application/pdf', $document['mimeType']);
        $this->assertSame(0, $document['index']);
    }

    public function testUnlistableDirectoryDegradesToANote(): void {
        $controller = $this->makeController();

        $this->fileService->method('getInfo')->willReturn(self::item('docs', 'folder', 0, 'httpd/unix-directory'));
        $this->fileService->method('listDirectory')
            ->willThrowException(new NotFoundException('/docs'));

        $result = $this->buildBlocks($controller, ['/docs']);
        $blocks = $result['blocks'];

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('--- Directory: docs (0 files, 0 included as content) ---', $blocks[0]['text']);
        $this->assertStringContainsString('(could not be listed)', $blocks[0]['text']);
    }

    public function testPlainFileAttachmentKeepsItsSingleBlock(): void {
        $controller = $this->makeController();

        $this->fileService->method('getInfo')->willReturn(self::item('solo.md', 'file', 4, 'text/markdown'));
        $this->fileService->method('getContent')->willReturn(
            self::textContent('solo.md', 'solo', 'text/markdown')
        );

        $result = $this->buildBlocks($controller, ['/solo.md']);

        $this->assertCount(1, $result['blocks']);
        $this->assertSame("--- File: solo.md (text/markdown, 4 bytes) ---\nsolo", $result['blocks'][0]['text']);
        $this->assertSame([], $result['documents']);
    }

    /**
     * The failure mode this whole expansion exists to fix: the user attaches
     * a directory in one turn, then asks about a file inside it in a later
     * turn. History must carry the directory's files forward, or the model
     * only sees the plain prompt text and demands a fresh /add-file.
     */
    public function testAttachedDirectoryStaysInContextOnLaterTurns(): void {
        $controller = $this->makeController();

        $first = new MessageEntity();
        $first->setId(1);
        $first->setRole('user');
        $first->setContent('');
        $second = new MessageEntity();
        $second->setId(2);
        $second->setRole('user');
        $second->setContent('What is in a.md?');

        $this->messageMapper->method('findByConversation')->willReturn([$first, $second]);

        $attached = new MessageFile();
        $attached->setFilePath('/docs');
        $this->messageFileMapper->method('findByMessage')->willReturnCallback(
            static function (int $messageId) use ($attached): array {
                return $messageId === 1 ? [$attached] : [];
            }
        );

        $this->fileService->method('getInfo')->willReturn(self::item('docs', 'folder', 0, 'httpd/unix-directory'));
        $this->fileService->method('listDirectory')->willReturn([
            'path' => '/docs',
            'count' => 1,
            'items' => [self::item('a.md', 'file', 3, 'text/markdown')],
        ]);
        $this->fileService->method('getContent')->willReturn(
            self::textContent('a.md', 'AAA', 'text/markdown')
        );

        $documentsIndex = [];
        $method = new \ReflectionMethod(ConversationController::class, 'buildHistoryMessages');
        $messages = $method->invoke($controller, 7, $documentsIndex);

        $this->assertCount(2, $messages);
        // First turn: the directory is re-expanded into content blocks, with
        // the user's (empty) prompt preserved as a text block would have been.
        $this->assertIsArray($messages[0]['content']);
        $this->assertContains("--- File: a.md (text/markdown, 3 bytes) ---\nAAA", $this->blockTexts($messages[0]['content']));
        // Second turn: plain text, untouched.
        $this->assertSame('What is in a.md?', $messages[1]['content']);
        $this->assertSame([], $documentsIndex);
    }
}
