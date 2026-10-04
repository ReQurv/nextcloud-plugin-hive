// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchNotesAPI, type Note } from '../../client/notes.js';
import { handleAppError } from '../error-utils.js';
import { getWebDAVClient } from '../../client/webdav.js';

/**
 * Nextcloud Notes App Tools
 * Uses the Notes REST API v1 (/index.php/apps/notes/api/v1)
 */

const notesStatusMap: Record<number, string> = {
  404: 'Note not found.',
  403: 'Note is read-only.',
  412: 'Conflict: note was modified by someone else. Fetch the latest version and retry.',
};

function formatNote(note: Note): string {
  const date = new Date(note.modified * 1000).toISOString();
  const flags = [note.favorite ? 'favorite' : null, note.readonly ? 'readonly' : null]
    .filter(Boolean)
    .join(', ');
  const meta = [note.category ? `category: ${note.category}` : null, flags || null]
    .filter(Boolean)
    .join(' | ');
  return `[${note.id}] ${note.title}${meta ? ` (${meta})` : ''} — modified: ${date}`;
}

export const listNotesTool = {
  name: 'list_notes',
  title: 'List Notes',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List all notes in Nextcloud Notes. Returns id, title, category, favorite flag, and modification date.',
  inputSchema: z.object({
    category: z.string().optional().describe('Filter by category'),
    search: z.string().optional().describe('Filter notes by title (client-side)'),
  }),
  handler: async (args: { category?: string; search?: string }) => {
    try {
      const params: Record<string, string> = { exclude: 'content' };
      if (args.category) params.category = args.category;

      let notes = await fetchNotesAPI<Note[]>('/notes', { queryParams: params });

      if (args.search) {
        const q = args.search.toLowerCase();
        notes = notes.filter((n) => n.title.toLowerCase().includes(q));
      }

      if (notes.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No notes found.' }] };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Notes (${notes.length}):\n\n${notes.map(formatNote).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing notes', notesStatusMap);
    }
  },
};

export const getNoteTool = {
  name: 'get_note',
  title: 'Get Note',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get the full content of a note by its ID.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const note = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      return {
        content: [
          {
            type: 'text' as const,
            text: `# ${note.title}\n\n${note.content}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error getting note', notesStatusMap);
    }
  },
};

export const createNoteTool = {
  name: 'create_note',
  title: 'Create Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Create a new note in Nextcloud Notes.',
  inputSchema: z.object({
    title: z.string().describe('Title of the note'),
    content: z.string().describe('Content of the note (Markdown)'),
    category: z.string().optional().describe('Category (maps to a subfolder)'),
    favorite: z.boolean().optional().describe('Mark as favorite'),
  }),
  handler: async (args: {
    title: string;
    content: string;
    category?: string;
    favorite?: boolean;
  }) => {
    try {
      const note = await fetchNotesAPI<Note>('/notes', {
        method: 'POST',
        body: {
          title: args.title,
          content: args.content,
          category: args.category ?? '',
          favorite: args.favorite ?? false,
        },
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note created: ${formatNote(note)}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating note', notesStatusMap);
    }
  },
};

export const updateNoteTool = {
  name: 'update_note',
  title: 'Update Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Update an existing note. Provide only the fields you want to change.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
    title: z.string().optional().describe('New title'),
    content: z.string().optional().describe('New content (Markdown)'),
    category: z.string().optional().describe('New category'),
    favorite: z.boolean().optional().describe('Favorite flag'),
  }),
  handler: async (args: {
    id: number;
    title?: string;
    content?: string;
    category?: string;
    favorite?: boolean;
  }) => {
    try {
      // Fetch current note to get etag and merge fields
      const current = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      const updated = await fetchNotesAPI<Note>(`/notes/${args.id}`, {
        method: 'PUT',
        ifMatch: current.etag,
        body: {
          title: args.title ?? current.title,
          content: args.content ?? current.content,
          category: args.category ?? current.category,
          favorite: args.favorite ?? current.favorite,
        },
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note updated: ${formatNote(updated)}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating note', notesStatusMap);
    }
  },
};

export const deleteNoteTool = {
  name: 'delete_note',
  title: 'Delete Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a note by its ID.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchNotesAPI(`/notes/${args.id}`, { method: 'DELETE' });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note ${args.id} deleted.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting note', notesStatusMap);
    }
  },
};

// ---------------------------------------------------------------------------
// Search (client-side token scoring, mirroring the official Notes search)
// ---------------------------------------------------------------------------

const TITLE_WEIGHT = 3.0;
const CONTENT_WEIGHT = 1.0;
const MIN_SEARCH_SCORE = 0.5;

function tokenize(value: string): string[] {
  return value
    .toLowerCase()
    .split(/\s+/)
    .filter((token) => token.length > 1);
}

function scoreNote(queryTokens: string[], note: Note): number {
  const titleTokens = note.title.toLowerCase().split(/\s+/);
  const contentTokens = note.content.toLowerCase().split(/\s+/);
  const titleMatches = queryTokens.filter((qt) => titleTokens.includes(qt)).length;
  const contentMatches = queryTokens.filter((qt) => contentTokens.includes(qt)).length;
  if (titleMatches === 0 && contentMatches === 0) return 0;
  const titleRatio = titleMatches / queryTokens.length;
  const contentRatio = contentMatches / queryTokens.length;
  return TITLE_WEIGHT * titleRatio + CONTENT_WEIGHT * contentRatio;
}

export const searchNotesTool = {
  name: 'search_notes',
  title: 'Search Notes',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Search all notes by title or content, ranked by relevance. Returns only id, title, and category for each match. An empty query returns all notes unranked.',
  inputSchema: z.object({
    query: z
      .string()
      .describe('Search query; whitespace-separated tokens matched against title and content'),
  }),
  handler: async (args: { query: string }) => {
    try {
      const notes = await fetchNotesAPI<Note[]>('/notes');
      const queryTokens = tokenize(args.query);

      const hits: Array<{ note: Note; score: number | null }> =
        queryTokens.length === 0
          ? notes.map((note) => ({ note, score: null }))
          : notes
              .map((note) => ({ note, score: scoreNote(queryTokens, note) }))
              .filter((h) => (h.score ?? 0) >= MIN_SEARCH_SCORE)
              .sort((a, b) => (b.score ?? 0) - (a.score ?? 0));

      if (hits.length === 0) {
        return {
          content: [{ type: 'text' as const, text: `No notes matched "${args.query}".` }],
        };
      }

      const lines = hits.map(({ note, score }) => {
        const base = `[${note.id}] ${note.title}${note.category ? ` (category: ${note.category})` : ''}`;
        return score === null ? base : `${base} — score: ${score.toFixed(2)}`;
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Search results for "${args.query}" (${hits.length} found):\n\n${lines.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error searching notes', notesStatusMap);
    }
  },
};

// ---------------------------------------------------------------------------
// Append content
// ---------------------------------------------------------------------------

export const appendContentTool = {
  name: 'append_content',
  title: 'Append to Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Append content to an existing note. A horizontal rule (`---`) is inserted between the existing content and the appended content.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
    content: z.string().min(1).describe('Content to append (Markdown)'),
  }),
  handler: async (args: { id: number; content: string }) => {
    try {
      const current = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      const separator = '\n---\n';
      const newContent = current.content
        ? `${current.content}${separator}${args.content}`
        : args.content;
      const updated = await fetchNotesAPI<Note>(`/notes/${args.id}`, {
        method: 'PUT',
        ifMatch: current.etag,
        body: {
          title: current.title,
          content: newContent,
          category: current.category,
          favorite: current.favorite,
        },
      });
      return {
        content: [{ type: 'text' as const, text: `Content appended: ${formatNote(updated)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error appending to note', notesStatusMap);
    }
  },
};

// ---------------------------------------------------------------------------
// Get attachment (WebDAV)
// ---------------------------------------------------------------------------

const TEXT_LIKE_MIME_TYPES = [
  'application/json',
  'application/xml',
  'application/yaml',
  'application/x-yaml',
  'application/javascript',
  'application/sql',
  'image/svg+xml',
];

export const getAttachmentTool = {
  name: 'get_attachment',
  title: 'Get Note Attachment',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get a specific attachment from a note. Note attachments live in WebDAV under Notes/.attachments.{note_id}/. Returns text directly for text attachments, an image block for images, and base64 otherwise.',
  inputSchema: z.object({
    noteId: z.number().int().describe('Note ID (from list_notes)'),
    filename: z.string().describe('Attachment filename (e.g. "photo.png")'),
  }),
  handler: async (args: { noteId: number; filename: string }) => {
    try {
      const client = getWebDAVClient();
      const path = `Notes/.attachments.${args.noteId}/${args.filename}`;
      const statResult = await client.stat(path);
      const info = 'data' in statResult ? statResult.data : statResult;
      const mimeType = info.mime || 'application/octet-stream';
      const size = typeof info.size === 'number' ? info.size : 0;

      if (mimeType.startsWith('text/') || TEXT_LIKE_MIME_TYPES.includes(mimeType)) {
        const content = (await client.getFileContents(path, { format: 'text' })) as string;
        return {
          content: [
            {
              type: 'text' as const,
              text: `Attachment: ${args.filename} (${mimeType})\n\n${content}`,
            },
          ],
        };
      }

      const bytes = await client.getFileContents(path, { format: 'binary' });
      const data = Buffer.from(bytes as ArrayBuffer).toString('base64');
      if (mimeType.startsWith('image/')) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Attachment: ${args.filename} (${mimeType}, ${size} bytes)`,
            },
            { type: 'image' as const, data, mimeType },
          ],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Attachment: ${args.filename} (${mimeType}, ${size} bytes)\nEncoding: base64\n\n${data}`,
          },
        ],
      };
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      if (/404|not found/i.test(msg)) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Attachment ${args.filename} not found for note ${args.noteId}.`,
            },
          ],
          isError: true,
        };
      }
      return {
        content: [{ type: 'text' as const, text: `Error getting attachment: ${msg}` }],
        isError: true,
      };
    }
  },
};

export const notesTools = [
  listNotesTool,
  getNoteTool,
  createNoteTool,
  updateNoteTool,
  deleteNoteTool,
  searchNotesTool,
  appendContentTool,
  getAttachmentTool,
];
