// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchNotesAPI = vi.fn();
vi.mock('../client/notes.js', () => ({
  fetchNotesAPI: (...args: unknown[]) => mockFetchNotesAPI(...args),
}));

const mockStat = vi.fn();
const mockGetFileContents = vi.fn();
vi.mock('../client/webdav.js', () => ({
  getWebDAVClient: () => ({
    stat: (...args: unknown[]) => mockStat(...args),
    getFileContents: (...args: unknown[]) => mockGetFileContents(...args),
  }),
  resetWebDAVClient: vi.fn(),
}));

describe('Note Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_notes', () => {
    it('should return formatted note list', async () => {
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: 'personal',
          favorite: true,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (2)');
    });

    it('should filter notes by search term', async () => {
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({ search: 'meeting' });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).not.toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (1)');
    });

    it('should handle empty notes', async () => {
      mockFetchNotesAPI.mockResolvedValue([]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('No notes found');
    });

    it('should handle errors', async () => {
      mockFetchNotesAPI.mockRejectedValue(new Error('Network error'));

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('get_note', () => {
    it('should return note content', async () => {
      mockFetchNotesAPI.mockResolvedValue({
        id: 1,
        title: 'Meeting Notes',
        content: 'Discussed project timeline',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      });

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Discussed project timeline');
    });

    it('should handle nonexistent note', async () => {
      const { ApiError } = await import('../client/requrvhive.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('update_note', () => {
    it('should fetch current note then update', async () => {
      const current = {
        id: 1,
        title: 'Old Title',
        content: 'Old content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      const updated = { ...current, title: 'New Title', etag: 'xyz' };
      mockFetchNotesAPI.mockResolvedValueOnce(current).mockResolvedValueOnce(updated);

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, title: 'New Title' });

      expect(result.content[0].text).toContain('New Title');
      expect(mockFetchNotesAPI).toHaveBeenCalledTimes(2);
    });

    it('should handle conflict (412)', async () => {
      const { ApiError } = await import('../client/requrvhive.js');
      const current = {
        id: 1,
        title: 'Note',
        content: 'Content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      mockFetchNotesAPI
        .mockResolvedValueOnce(current)
        .mockRejectedValueOnce(new ApiError(412, 'Precondition Failed', ''));

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, content: 'New content' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Conflict');
    });
  });

  describe('delete_note', () => {
    it('should delete a note by id', async () => {
      mockFetchNotesAPI.mockResolvedValue(undefined);

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted');
      expect(mockFetchNotesAPI).toHaveBeenCalledWith('/notes/1', { method: 'DELETE' });
    });

    it('should handle nonexistent note on delete', async () => {
      const { ApiError } = await import('../client/requrvhive.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('search_notes', () => {
    const noteFixture = [
      {
        id: 1,
        title: 'Meeting Notes',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'a1',
        content: 'agenda about the quarterly budget',
      },
      {
        id: 2,
        title: 'Shopping List',
        category: 'personal',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'a2',
        content: 'milk and eggs',
      },
      {
        id: 3,
        title: 'Budget Draft',
        category: 'work',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'a3',
        content: 'quarterly numbers for the board',
      },
    ];

    it('should rank matches by title and content score', async () => {
      mockFetchNotesAPI.mockResolvedValue(noteFixture);

      const { searchNotesTool } = await import('../tools/apps/notes.js');
      const result = await searchNotesTool.handler({ query: 'quarterly budget' });

      const textOut = result.content[0].text;
      expect(textOut).toContain('2 found');
      // Title match (weight 3) outranks content-only match (weight 1)
      expect(textOut.indexOf('[3]')).toBeLessThan(textOut.indexOf('[1]'));
      expect(textOut).toContain('score: 2.00');
      expect(textOut).toContain('score: 1.00');
      expect(textOut).not.toContain('Shopping List');
    });

    it('should return all notes unranked for an empty query', async () => {
      mockFetchNotesAPI.mockResolvedValue(noteFixture);

      const { searchNotesTool } = await import('../tools/apps/notes.js');
      const result = await searchNotesTool.handler({ query: '  ' });

      const textOut = result.content[0].text;
      expect(textOut).toContain('3 found');
      expect(textOut).toContain('[1] Meeting Notes');
      expect(textOut).toContain('[2] Shopping List');
      expect(textOut).not.toContain('score:');
    });

    it('should report when nothing matches', async () => {
      mockFetchNotesAPI.mockResolvedValue(noteFixture);

      const { searchNotesTool } = await import('../tools/apps/notes.js');
      const result = await searchNotesTool.handler({ query: 'nonexistenttoken' });

      expect(result.content[0].text).toContain('No notes matched');
    });

    it('should handle API errors', async () => {
      const { ApiError } = await import('../client/requrvhive.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(500, 'Server Error', ''));

      const { searchNotesTool } = await import('../tools/apps/notes.js');
      const result = await searchNotesTool.handler({ query: 'budget' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error searching notes');
    });
  });

  describe('append_content', () => {
    it('should append with a separator and keep note fields', async () => {
      mockFetchNotesAPI
        .mockResolvedValueOnce({
          id: 1,
          title: 'Log',
          category: 'work',
          favorite: true,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: 'Hello',
        })
        .mockResolvedValueOnce({
          id: 1,
          title: 'Log',
          category: 'work',
          favorite: true,
          readonly: false,
          modified: 1734220801,
          etag: 'def',
          content: 'Hello\n---\nWorld',
        });

      const { appendContentTool } = await import('../tools/apps/notes.js');
      const result = await appendContentTool.handler({ id: 1, content: 'World' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('Content appended');
      expect(mockFetchNotesAPI).toHaveBeenNthCalledWith(1, '/notes/1');
      expect(mockFetchNotesAPI).toHaveBeenNthCalledWith(2, '/notes/1', {
        method: 'PUT',
        ifMatch: 'abc',
        body: {
          title: 'Log',
          content: 'Hello\n---\nWorld',
          category: 'work',
          favorite: true,
        },
      });
    });

    it('should not add a separator to an empty note', async () => {
      mockFetchNotesAPI
        .mockResolvedValueOnce({
          id: 2,
          title: 'Empty',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        })
        .mockResolvedValueOnce({
          id: 2,
          title: 'Empty',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220801,
          etag: 'def',
          content: 'First',
        });

      const { appendContentTool } = await import('../tools/apps/notes.js');
      await appendContentTool.handler({ id: 2, content: 'First' });

      expect(mockFetchNotesAPI).toHaveBeenNthCalledWith(2, '/notes/2', {
        method: 'PUT',
        ifMatch: 'abc',
        body: { title: 'Empty', content: 'First', category: '', favorite: false },
      });
    });

    it('should handle nonexistent note', async () => {
      const { ApiError } = await import('../client/requrvhive.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { appendContentTool } = await import('../tools/apps/notes.js');
      const result = await appendContentTool.handler({ id: 999, content: 'x' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('get_attachment', () => {
    const attachmentPath = 'Notes/.attachments.7/doc.txt';

    it('should return text attachments as text', async () => {
      mockStat.mockResolvedValue({ mime: 'text/plain', size: 5 });
      mockGetFileContents.mockResolvedValue('hello');

      const { getAttachmentTool } = await import('../tools/apps/notes.js');
      const result = await getAttachmentTool.handler({ noteId: 7, filename: 'doc.txt' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('doc.txt (text/plain)');
      expect(result.content[0].text).toContain('hello');
      expect(mockStat).toHaveBeenCalledWith(attachmentPath);
      expect(mockGetFileContents).toHaveBeenCalledWith(attachmentPath, { format: 'text' });
    });

    it('should return image attachments as image content blocks', async () => {
      const bytes = Buffer.from([0x89, 0x50]);
      mockStat.mockResolvedValue({ mime: 'image/png', size: 100 });
      mockGetFileContents.mockResolvedValue(bytes);

      const { getAttachmentTool } = await import('../tools/apps/notes.js');
      const result = await getAttachmentTool.handler({ noteId: 7, filename: 'photo.png' });

      expect(result.content[0].text).toContain('photo.png (image/png, 100 bytes)');
      expect(result.content[1].type).toBe('image');
      expect(result.content[1].data).toBe(bytes.toString('base64'));
      expect(result.content[1].mimeType).toBe('image/png');
      expect(mockGetFileContents).toHaveBeenCalledWith('Notes/.attachments.7/photo.png', {
        format: 'binary',
      });
    });

    it('should return other binary attachments base64-encoded', async () => {
      const bytes = Buffer.from('xpdf');
      mockStat.mockResolvedValue({ mime: 'application/pdf', size: 512 });
      mockGetFileContents.mockResolvedValue(bytes);

      const { getAttachmentTool } = await import('../tools/apps/notes.js');
      const result = await getAttachmentTool.handler({ noteId: 7, filename: 'file.pdf' });

      expect(result.content[0].text).toContain('Encoding: base64');
      expect(result.content[0].text).toContain(bytes.toString('base64'));
    });

    it('should report missing attachments', async () => {
      mockStat.mockRejectedValue(new Error('404 Not Found'));

      const { getAttachmentTool } = await import('../tools/apps/notes.js');
      const result = await getAttachmentTool.handler({ noteId: 7, filename: 'nope.txt' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found for note 7');
    });
  });
});
