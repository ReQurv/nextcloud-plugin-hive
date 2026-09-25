<?php

namespace OCA\RequrvHive\Tests\Unit;

use OCA\RequrvHive\Service\ChatToolsService;
use OCA\RequrvHive\Service\FileService;
use OCA\RequrvHive\Service\ImageOptimizer;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The chat's built-in file tools are the surface the model can touch to
 * answer from the user's storage without any attachment or MCP server. The
 * invariants that matter: every result is shaped like an MCP tool result so
 * the provider loop treats both sources identically, a failure is an isError
 * result (never an exception escaping to the chat), and read_image is the
 * only tool that can hand an image block to a vision model.
 */
class ChatToolsServiceTest extends TestCase {
    private FileService $files;
    private ImageOptimizer $images;
    private ChatToolsService $tools;

    protected function setUp(): void {
        $this->files = $this->createMock(FileService::class);
        $this->images = $this->createMock(ImageOptimizer::class);
        $this->tools = new ChatToolsService($this->files, $this->images, $this->createMock(LoggerInterface::class));
    }

    private static function item(string $name, string $type, int $size = 0, string $mime = 'text/plain', int $mtime = 1700000000): array {
        return [
            'name' => $name,
            'path' => '/' . $name,
            'size' => $size,
            'mimeType' => $mime,
            'mtime' => $mtime,
            'etag' => '',
            'permissions' => 31,
            'type' => $type,
            'isEncrypted' => false,
            'id' => 0,
        ];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function text(array $result): string {
        $this->assertSame(1, count($result['content']));
        return (string)$result['content'][0]['text'];
    }

    public function testOffersTheFiveFileToolsInCanonicalShape(): void {
        $tools = $this->tools->getTools();
        $names = array_map(static fn(array $t): string => (string)$t['name'], $tools);

        $this->assertSame(
            ['list_directory', 'get_file_info', 'read_file', 'search_files', 'read_image'],
            $names
        );
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('description', $tool);
            $this->assertSame('object', $tool['input_schema']['type'] ?? null);
            $this->assertNotEmpty($tool['input_schema']['required'] ?? []);
        }
    }

    public function testBuiltinNamesAreRecognised(): void {
        foreach (['list_directory', 'get_file_info', 'read_file', 'search_files', 'read_image'] as $name) {
            $this->assertTrue(ChatToolsService::isBuiltin($name), $name);
        }
        $this->assertFalse(ChatToolsService::isBuiltin('mcp__read_file'));
        $this->assertFalse(ChatToolsService::isBuiltin('delete'));
    }

    public function testListDirectoryShowsEntriesWithPaths(): void {
        $this->files->method('listDirectory')->with('Documents', 'user')->willReturn([
            'path' => 'Documents',
            'count' => 2,
            'items' => [
                self::item('notes.txt', 'file', 12, 'text/plain'),
                self::item('invoices', 'folder', 0, 'httpd/unix-directory'),
            ],
        ]);

        $result = $this->tools->execute('list_directory', ['path' => '/Documents'], 'user');
        $this->assertFalse($result['isError']);
        $text = $this->text($result);
        $this->assertStringContainsString('invoices/  (directory — Documents/invoices)', $text);
        $this->assertStringContainsString('notes.txt  (12 B, text/plain', $text);
    }

    public function testListDirectoryRecursiveDescends(): void {
        $this->files->method('listDirectory')->willReturnCallback(
            static function (string $path): array {
                return match ($path) {
                    'Documents' => ['path' => $path, 'count' => 1, 'items' => [self::item('invoices', 'folder')]],
                    'Documents/invoices' => ['path' => $path, 'count' => 1, 'items' => [self::item('2026.pdf', 'file', 10, 'application/pdf')]],
                    default => throw new NotFoundException($path),
                };
            }
        );

        $result = $this->tools->execute('list_directory', ['path' => 'Documents', 'recursive' => true], 'user');
        $this->assertFalse($result['isError']);
        $text = $this->text($result);
        $this->assertStringContainsString('invoices/', $text);
        $this->assertStringContainsString('2026.pdf', $text);
    }

    public function testGetFileInfoReportsMetadata(): void {
        $this->files->method('getInfo')->with('Docs/report.md', 'user')->willReturn(
            self::item('report.md', 'file', 4096, 'text/markdown', 1700000123)
        );

        $result = $this->tools->execute('get_file_info', ['path' => 'Docs/report.md'], 'user');
        $this->assertFalse($result['isError']);
        $text = $this->text($result);
        $this->assertStringContainsString('name: report.md', $text);
        $this->assertStringContainsString('type: file', $text);
        $this->assertStringContainsString('size: 4 KB', $text);
        $this->assertStringContainsString('mime: text/markdown', $text);
    }

    public function testReadFileReturnsTextContent(): void {
        $this->files->method('getContent')->with('notes.txt', 'user')->willReturn([
            'name' => 'notes.txt', 'mimeType' => 'text/plain', 'size' => 5, 'encoding' => 'text', 'content' => "hello",
        ]);

        $result = $this->tools->execute('read_file', ['path' => 'notes.txt'], 'user');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('hello', $this->text($result));
    }

    public function testReadFileTruncatesOversizedContent(): void {
        $body = str_repeat('a', 3000);
        $this->files->method('getContent')->willReturn([
            'name' => 'big.txt', 'mimeType' => 'text/plain', 'size' => strlen($body), 'encoding' => 'text', 'content' => $body,
        ]);

        $result = $this->tools->execute('read_file', ['path' => 'big.txt', 'max_bytes' => 2000], 'user');
        $this->assertFalse($result['isError']);
        $text = $this->text($result);
        $this->assertStringContainsString('[content truncated', $text);

        $lines = explode("\n", $text);
        $this->assertSame(2000, strlen($lines[1]), 'the body must be cut to exactly max_bytes');
    }

    public function testReadFileOnAnImageSuggestsReadImage(): void {
        $this->files->method('getContent')->willReturn([
            'name' => 'pic.png', 'mimeType' => 'image/png', 'size' => 100, 'encoding' => 'base64', 'content' => base64_encode('x'),
        ]);

        $result = $this->tools->execute('read_file', ['path' => 'pic.png'], 'user');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('read_image', $this->text($result));
    }

    public function testSearchFilesReportsMatchesWithBoundedList(): void {
        $results = [];
        for ($i = 0; $i < 60; $i++) {
            $results[] = self::item('file-' . $i . '.txt', 'file', 10, 'text/plain');
        }
        $this->files->method('search')->willReturn([
            'query' => 'file', 'mimeFilter' => null, 'count' => 60, 'results' => $results,
        ]);

        $result = $this->tools->execute('search_files', ['query' => 'file'], 'user');
        $this->assertFalse($result['isError']);
        $text = $this->text($result);
        $this->assertSame(50, substr_count($text, 'file-'));
        $this->assertStringContainsString('file-0.txt', $text);
        $this->assertStringNotContainsString('file-59.txt', $text);
    }

    public function testSearchFilesWithNoHitsIsNotAnError(): void {
        $this->files->method('search')->willReturn([
            'query' => 'nope', 'mimeFilter' => null, 'count' => 0, 'results' => [],
        ]);

        $result = $this->tools->execute('search_files', ['query' => 'nope'], 'user');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('No files matching', $this->text($result));
    }

    public function testReadImageReturnsMetadataPlusAnImageBlock(): void {
        $raw = 'imagedata';
        $this->files->method('getContent')->with('photos/cat.jpg', 'user')->willReturn([
            'name' => 'cat.jpg', 'mimeType' => 'image/jpeg', 'size' => strlen($raw), 'encoding' => 'base64', 'content' => base64_encode($raw),
        ]);
        $this->images->method('isSupported')->with('image/jpeg')->willReturn(true);
        $this->images->method('prepare')->with($raw, 'image/jpeg')->willReturn([
            'base64' => base64_encode($raw), 'mimeType' => 'image/jpeg',
        ]);

        $result = $this->tools->execute('read_image', ['path' => '/photos/cat.jpg'], 'user');
        $this->assertFalse($result['isError']);
        $this->assertCount(2, $result['content']);
        $this->assertStringContainsString('cat.jpg', (string)$result['content'][0]['text']);

        $image = $result['content'][1];
        $this->assertSame('image', $image['type']);
        $this->assertSame('base64', $image['source']['type']);
        $this->assertSame('image/jpeg', $image['source']['media_type']);
        $this->assertSame($raw, base64_decode((string)$image['source']['data']));
    }

    public function testReadImageRejectsNonImages(): void {
        $this->files->method('getContent')->willReturn([
            'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => 1, 'encoding' => 'base64', 'content' => base64_encode('x'),
        ]);

        $result = $this->tools->execute('read_image', ['path' => 'a.pdf'], 'user');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('read_file', $this->text($result));
    }

    public function testUnknownToolIsAnErrorResult(): void {
        $result = $this->tools->execute('delete_everything', [], 'user');
        $this->assertTrue($result['isError']);
    }

    public function testMissingFileIsAnErrorResultNotAnException(): void {
        $this->files->method('getInfo')->willThrowException(new NotFoundException('missing'));

        $result = $this->tools->execute('get_file_info', ['path' => 'missing'], 'user');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Not found', $this->text($result));
    }
}
