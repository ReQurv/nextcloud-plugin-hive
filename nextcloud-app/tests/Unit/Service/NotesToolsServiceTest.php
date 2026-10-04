<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Service;

use OCA\RequrvHive\Service\NotesToolsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The notes tools work on the plain text files the notes app stores in the
 * user's Notes folder (first line = title, subfolders = categories). The
 * tests pin the user-facing contract: listing is scoped to that folder, the
 * snippet comes from the note's own text, and create_note never overwrites —
 * a duplicate title is an error the model can react to.
 */
class NotesToolsServiceTest extends TestCase {
    private IRootFolder $rootFolder;
    private Folder $userFolder;
    private NotesToolsService $service;

    protected function setUp(): void {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);
        $this->service = new NotesToolsService($this->rootFolder, $this->createMock(LoggerInterface::class));
    }

    private function makeFile(string $name, string $content, int $mtime = 1760000000): File {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMTime')->willReturn($mtime);

        return $file;
    }

    public function testIsToolRecognisesOnlyTheNotesTools(): void {
        $this->assertTrue(NotesToolsService::isTool(NotesToolsService::TOOL_LIST_NOTES));
        $this->assertTrue(NotesToolsService::isTool(NotesToolsService::TOOL_GET_NOTE));
        $this->assertTrue(NotesToolsService::isTool(NotesToolsService::TOOL_CREATE_NOTE));
        $this->assertFalse(NotesToolsService::isTool('delete_note'));
        $this->assertFalse(NotesToolsService::isTool('list_calendars'));
    }

    public function testGetToolsOffersThreeToolsInCanonicalShape(): void {
        $tools = $this->service->getTools();
        $names = array_map(static fn(array $t): string => (string)$t['name'], $tools);

        $this->assertSame(['list_notes', 'get_note', 'create_note'], $names);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('description', $tool);
            $this->assertSame('object', $tool['input_schema']['type'] ?? null);
        }
    }

    public function testListNotesShowsTitleCategoryAndSnippet(): void {
        $todo = $this->makeFile('todo.txt', "Groceries\n\n- milk\n- eggs");
        $idea = $this->makeFile('app idea.txt', "App idea\nBuild it");

        $ideas = $this->createMock(Folder::class);
        $ideas->method('getName')->willReturn('Ideas');
        $ideas->method('getDirectoryListing')->willReturn([$idea]);

        $notes = $this->createMock(Folder::class);
        $notes->method('getDirectoryListing')->willReturn([$todo, $ideas]);

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(true);
        $this->userFolder->method('get')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_LIST_NOTES, [], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('Notes (path — title, last edited):', $text);
        $this->assertStringContainsString('- Ideas/app idea.txt — App idea', $text);
        $this->assertStringContainsString('- todo.txt — Groceries', $text);
        $this->assertStringContainsString(': - milk', $text);
        // Categories sort before top-level notes.
        $this->assertLessThan(
            mb_strpos($text, 'todo.txt'),
            mb_strpos($text, 'Ideas/app idea.txt')
        );
    }

    public function testListNotesWithoutANotesFolderIsNotAnError(): void {
        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(false);

        $result = $this->service->execute(NotesToolsService::TOOL_LIST_NOTES, [], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('no notes yet', (string)$result['content'][0]['text']);
    }

    public function testGetNoteReturnsTheFullText(): void {
        $note = $this->makeFile('app idea.txt', "App idea\nBuild it");
        $notes = $this->createMock(Folder::class);
        $notes->method('nodeExists')->with('Ideas/app idea.txt')->willReturn(true);
        $notes->method('get')->with('Ideas/app idea.txt')->willReturn($note);

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(true);
        $this->userFolder->method('get')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_GET_NOTE, [
            'path' => 'Ideas/app idea.txt',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString("Note 'Notes/Ideas/app idea.txt'", $text);
        $this->assertStringContainsString("App idea\nBuild it", $text);
    }

    public function testGetNoteResolvesABareTitleToItsStoredFile(): void {
        $note = $this->makeFile('app idea.txt', "App idea\nBuild it");
        $notes = $this->createMock(Folder::class);
        $notes->method('nodeExists')->willReturnMap([
            ['Ideas/app idea', false],
            ['Ideas/app idea.txt', true],
        ]);
        $notes->method('get')->with('Ideas/app idea.txt')->willReturn($note);

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(true);
        $this->userFolder->method('get')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_GET_NOTE, [
            'path' => 'Ideas/app idea',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString("App idea\nBuild it", (string)$result['content'][0]['text']);
    }

    public function testGetNoteWithoutANotesFolderIsAnErrorResult(): void {
        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(false);

        $result = $this->service->execute(NotesToolsService::TOOL_GET_NOTE, [
            'path' => 'todo.txt',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Not found', (string)$result['content'][0]['text']);
    }

    public function testCreateNoteWritesTitleFirstLineAndAddsTheDefaultExtension(): void {
        $created = null;
        $newFile = $this->createMock(File::class);
        $newFile->expects($this->once())->method('putContent')->willReturnCallback(
            static function (string $content) use (&$created): void {
                $created = $content;
            }
        );

        $notes = $this->createMock(Folder::class);
        $notes->method('nodeExists')->with('App idea.txt')->willReturn(false);
        $notes->method('newFile')->with('App idea.txt')->willReturn($newFile);

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(false);
        $this->userFolder->method('newFolder')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_CREATE_NOTE, [
            'title' => 'App idea',
            'content' => "Build it\n\n- step one\n- step two",
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString("Note 'Notes/App idea.txt' created", (string)$result['content'][0]['text']);
        $this->assertSame("App idea\nBuild it\n\n- step one\n- step two", $created);
    }

    public function testCreateNoteIntoAnExistingCategoryKeepsTheNoteThere(): void {
        $category = $this->createMock(Folder::class);
        $category->method('nodeExists')->with('Run errands.txt')->willReturn(false);
        $newFile = $this->createMock(File::class);
        $newFile->expects($this->once())->method('putContent');
        $category->method('newFile')->with('Run errands.txt')->willReturn($newFile);

        $notes = $this->createMock(Folder::class);
        $notes->method('nodeExists')->with('Personal')->willReturn(true);
        $notes->method('get')->with('Personal')->willReturn($category);

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(true);
        $this->userFolder->method('get')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_CREATE_NOTE, [
            'title' => 'Run errands',
            'content' => 'Milk',
            'category' => 'Personal',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString("Note 'Notes/Personal/Run errands.txt' created", (string)$result['content'][0]['text']);
    }

    public function testCreateNoteNeverOverwritesAnExistingNote(): void {
        $notes = $this->createMock(Folder::class);
        $notes->method('nodeExists')->with('App idea.txt')->willReturn(true);
        $notes->expects($this->never())->method('newFile');

        $this->userFolder->method('nodeExists')->with('Notes')->willReturn(true);
        $this->userFolder->method('get')->with('Notes')->willReturn($notes);

        $result = $this->service->execute(NotesToolsService::TOOL_CREATE_NOTE, [
            'title' => 'App idea',
            'content' => 'new',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('already exists', (string)$result['content'][0]['text']);
    }

    public function testCreateNoteRequiresATitle(): void {
        $result = $this->service->execute(NotesToolsService::TOOL_CREATE_NOTE, [
            'title' => '   ',
            'content' => 'body',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('title', (string)$result['content'][0]['text']);
    }

    public function testCreateNoteRejectsCategoryTraversal(): void {
        $result = $this->service->execute(NotesToolsService::TOOL_CREATE_NOTE, [
            'title' => 'Escaped',
            'content' => 'x',
            'category' => '../Documents',
        ], 'requrv');
        $this->assertTrue($result['isError']);
    }

    public function testUnknownToolIsAnErrorResult(): void {
        $result = $this->service->execute('delete_note', [], 'requrv');
        $this->assertTrue($result['isError']);
    }
}
