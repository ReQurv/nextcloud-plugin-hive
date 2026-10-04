// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock fetch for OCS
global.fetch = vi.fn();

describe('Talk Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  function mockOCS<T>(data: T) {
    const ocsResponse = {
      ocs: {
        meta: { status: 'ok', statuscode: 200, message: 'OK' },
        data,
      },
    };
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve(ocsResponse),
      text: () => Promise.resolve(JSON.stringify(ocsResponse)),
    });
  }

  function mockAPIError(status = 500) {
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: false,
      status,
      statusText: 'Internal Server Error',
      text: () => Promise.resolve('Server error'),
    });
  }

  // ── list_conversations ──────────────────────────────────────────────

  describe('list_conversations', () => {
    it('should list conversations', async () => {
      mockOCS([
        {
          token: 'abc123',
          name: 'General',
          displayName: 'General',
          type: 2,
          unreadMessages: 3,
          lastActivity: 1700000000,
        },
        {
          token: 'def456',
          name: 'DM',
          displayName: 'Alice',
          type: 1,
          unreadMessages: 0,
          lastActivity: 1700001000,
        },
      ]);

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.content[0].text).toContain('[abc123] General');
      expect(result.content[0].text).toContain('group');
      expect(result.content[0].text).toContain('unread: 3');
      expect(result.content[0].text).toContain('[def456] Alice');
      expect(result.content[0].text).toContain('one-on-one');
    });

    it('should return empty message when no conversations', async () => {
      mockOCS([]);

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.content[0].text).toBe('No conversations found.');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing conversations');
    });
  });

  // ── list_messages ───────────────────────────────────────────────────

  describe('list_messages', () => {
    it('should list messages with resolved rich text', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello {mention-user1}!',
          messageParameters: { 'mention-user1': { type: 'user', id: 'bob', name: 'Bob' } },
          timestamp: 1700000000,
          systemMessage: '',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Alice: Hello Bob!');
    });

    it('should resolve a placeholder that repeats in one message', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: '{user1} added {user1} to the call',
          messageParameters: { user1: { type: 'user', id: 'bob', name: 'Bob' } },
          timestamp: 1700000000,
          systemMessage: '',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Bob added Bob to the call');
      expect(result.content[0].text).not.toContain('{user1}');
    });

    it('should filter system messages by default', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello',
          messageParameters: {},
          timestamp: 1700000000,
          systemMessage: '',
        },
        {
          id: 2,
          actorId: 'system',
          actorDisplayName: '',
          message: 'Alice joined',
          messageParameters: {},
          timestamp: 1700000001,
          systemMessage: 'user_added',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Hello');
      expect(result.content[0].text).not.toContain('Alice joined');
    });

    it('should include system messages when requested', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello',
          messageParameters: {},
          timestamp: 1700000000,
          systemMessage: '',
        },
        {
          id: 2,
          actorId: 'system',
          actorDisplayName: '',
          message: 'Alice joined',
          messageParameters: {},
          timestamp: 1700000001,
          systemMessage: 'user_added',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({
        token: 'abc123',
        includeSystemMessages: true,
      });

      expect(result.content[0].text).toContain('Hello');
      expect(result.content[0].text).toContain('Alice joined');
    });

    it('should return empty message when no messages', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toBe('No messages found.');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing messages');
    });

    it('does not advance the read marker', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      await listMessagesTool.handler({ token: 'abc123' });

      const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(new URL(String(url)).searchParams.get('setReadMarker')).toBe('0');
    });
  });

  // ── send_message ────────────────────────────────────────────────────

  describe('send_message', () => {
    it('should send a message', async () => {
      mockOCS({
        id: 42,
        actorId: 'testuser',
        actorDisplayName: 'Test User',
        message: 'Hello everyone',
        messageParameters: {},
        timestamp: 1700000000,
        systemMessage: '',
      });

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      const result = await sendMessageTool.handler({
        token: 'abc123',
        message: 'Hello everyone',
      });

      expect(result.content[0].text).toContain('Message sent (ID: 42)');
      expect(result.content[0].text).toContain('Hello everyone');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      const result = await sendMessageTool.handler({
        token: 'abc123',
        message: 'Hello',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error sending message');
    });
  });

  // ── create_conversation ─────────────────────────────────────────────

  describe('create_conversation', () => {
    it('should create a group conversation', async () => {
      mockOCS({
        token: 'new123',
        name: 'Project X',
        displayName: 'Project X',
        type: 2,
      });

      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'Project X',
      });

      expect(result.content[0].text).toContain('[new123]');
      expect(result.content[0].text).toContain('Project X');
      expect(result.content[0].text).toContain('group');
    });

    it('should require invite for one-on-one', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'one-on-one',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('invite is required');
    });

    it('should require roomName for group', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('roomName is required');
    });

    it('should require roomName for public', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'public',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('roomName is required');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'Test',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating conversation');
    });
  });

  // ── list_participants ───────────────────────────────────────────────

  describe('list_participants', () => {
    it('should list participants with roles', async () => {
      mockOCS([
        { actorId: 'alice', displayName: 'Alice', participantType: 1, attendeeId: 10 },
        { actorId: 'bob', displayName: 'Bob', participantType: 3, attendeeId: 11 },
      ]);

      const { listParticipantsTool } = await import('../tools/apps/talk.js');
      const result = await listParticipantsTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('alice (Alice) — owner');
      expect(result.content[0].text).toContain('bob (Bob) — user');
      expect(result.content[0].text).toContain('attendeeId: 10');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listParticipantsTool } = await import('../tools/apps/talk.js');
      const result = await listParticipantsTool.handler({ token: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing participants');
    });
  });

  // ── add_participant ─────────────────────────────────────────────────

  describe('add_participant', () => {
    it('should add a participant', async () => {
      mockOCS({});

      const { addParticipantTool } = await import('../tools/apps/talk.js');
      const result = await addParticipantTool.handler({
        token: 'abc123',
        newParticipant: 'charlie',
      });

      expect(result.content[0].text).toContain('"charlie" added');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { addParticipantTool } = await import('../tools/apps/talk.js');
      const result = await addParticipantTool.handler({
        token: 'abc123',
        newParticipant: 'charlie',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error adding participant');
    });
  });

  // ── remove_participant ──────────────────────────────────────────────

  describe('remove_participant', () => {
    it('should remove a participant', async () => {
      mockOCS({});

      const { removeParticipantTool } = await import('../tools/apps/talk.js');
      const result = await removeParticipantTool.handler({
        token: 'abc123',
        attendeeId: 10,
      });

      expect(result.content[0].text).toContain('attendeeId: 10');
      expect(result.content[0].text).toContain('removed');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { removeParticipantTool } = await import('../tools/apps/talk.js');
      const result = await removeParticipantTool.handler({
        token: 'abc123',
        attendeeId: 10,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing participant');
    });
  });

  // ── delete_message ──────────────────────────────────────────────────

  describe('delete_message', () => {
    it('should delete a message', async () => {
      mockOCS({});

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      const result = await deleteMessageTool.handler({
        token: 'abc123',
        messageId: 42,
      });

      expect(result.content[0].text).toContain('Message 42 deleted');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      const result = await deleteMessageTool.handler({
        token: 'abc123',
        messageId: 42,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting message');
    });
  });

  // ── create_poll ─────────────────────────────────────────────────────

  describe('create_poll', () => {
    it('should create a poll', async () => {
      mockOCS({});

      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Best language?',
        options: ['TypeScript', 'Rust', 'Go'],
      });

      expect(result.content[0].text).toContain('Poll created');
      expect(result.content[0].text).toContain('Best language?');
      expect(result.content[0].text).toContain('3 options');
    });

    it('should reject polls with fewer than 2 options', async () => {
      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Yes?',
        options: ['Yes'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('at least 2 options');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Best?',
        options: ['A', 'B'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating poll');
    });
  });

  // ── react_to_message ────────────────────────────────────────────────

  describe('react_to_message', () => {
    it('should add a reaction', async () => {
      mockOCS({});

      const { reactToMessageTool } = await import('../tools/apps/talk.js');
      const result = await reactToMessageTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.content[0].text).toContain('👍');
      expect(result.content[0].text).toContain('message 42');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { reactToMessageTool } = await import('../tools/apps/talk.js');
      const result = await reactToMessageTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error reacting to message');
    });
  });

  // ── Chat endpoint version ───────────────────────────────────────────
  // Rooms and participants live under api/v4, but the chat endpoints are
  // api/v1 — calling them under v4 yields a 404 "Invalid query" from Talk.

  describe('chat endpoint version', () => {
    const chatV1 = 'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc123';

    function calledUrl(): string {
      const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      return String(url);
    }

    it('lists messages from the v1 chat endpoint', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      await listMessagesTool.handler({ token: 'abc123' });

      expect(calledUrl().split('?')[0]).toBe(chatV1);
    });

    it('sends messages to the v1 chat endpoint', async () => {
      mockOCS({
        id: 1,
        actorId: 'testuser',
        actorDisplayName: 'Test User',
        message: 'hi',
        messageParameters: {},
        timestamp: 1700000000,
        systemMessage: '',
      });

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      await sendMessageTool.handler({ token: 'abc123', message: 'hi' });

      expect(calledUrl()).toBe(chatV1);
    });

    it('deletes messages via the v1 chat endpoint', async () => {
      mockOCS({});

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      await deleteMessageTool.handler({ token: 'abc123', messageId: 42 });

      expect(calledUrl()).toBe(`${chatV1}/42`);
    });
  });

  // ── get_conversation ────────────────────────────────────────────────

  describe('talk_get_conversation', () => {
    it('should return conversation details', async () => {
      mockOCS({
        token: 'abc123',
        name: 'general',
        displayName: 'General',
        type: 2,
        unreadMessages: 3,
        lastActivity: 1700000000,
        description: 'Company-wide room',
        participantCount: 12,
        objectType: 'deck',
        objectId: 'proj-1',
      });

      const { getConversationTool } = await import('../tools/apps/talk.js');
      const result = await getConversationTool.handler({ token: 'abc123' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('[abc123] General (group)');
      expect(result.content[0].text).toContain('Unread messages: 3');
      expect(result.content[0].text).toContain('Description: Company-wide room');
      expect(result.content[0].text).toContain('Participants: 12');
      expect(result.content[0].text).toContain('Linked to: deck#proj-1');
    });

    it('should call the v4 room endpoint', async () => {
      mockOCS({
        token: 'abc123',
        name: 'General',
        displayName: 'General',
        type: 2,
        unreadMessages: 0,
        lastActivity: 0,
      });

      const { getConversationTool } = await import('../tools/apps/talk.js');
      await getConversationTool.handler({ token: 'abc123' });

      expect(String((global.fetch as ReturnType<typeof vi.fn>).mock.calls[0][0])).toBe(
        'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v4/room/abc123'
      );
    });

    it('should handle API errors', async () => {
      mockAPIError(404);

      const { getConversationTool } = await import('../tools/apps/talk.js');
      const result = await getConversationTool.handler({ token: 'missing' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error getting conversation');
    });
  });

  // ── mark_as_read ────────────────────────────────────────────────────

  describe('talk_mark_as_read', () => {
    function lastCall() {
      const calls = (global.fetch as ReturnType<typeof vi.fn>).mock.calls;
      const [url, init] = calls[calls.length - 1];
      return { url: String(url), init: init as RequestInit };
    }

    it('should mark everything as read when no message id is given', async () => {
      mockOCS({});

      const { markAsReadTool } = await import('../tools/apps/talk.js');
      const result = await markAsReadTool.handler({ token: 'abc123' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('marked as read');
      const call = lastCall();
      expect(call.url).toBe(
        'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc123/read'
      );
      expect(call.init.method).toBe('POST');
      expect(call.init.body).toBeUndefined();
    });

    it('should set the read marker to a specific message', async () => {
      mockOCS({});

      const { markAsReadTool } = await import('../tools/apps/talk.js');
      const result = await markAsReadTool.handler({ token: 'abc123', lastReadMessage: 42 });

      expect(result.content[0].text).toContain('up to message 42');
      const call = lastCall();
      expect(call.url).toBe(
        'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc123/read'
      );
      expect(call.init.body).toBe(JSON.stringify({ lastReadMessage: 42 }));
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { markAsReadTool } = await import('../tools/apps/talk.js');
      const result = await markAsReadTool.handler({ token: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error marking conversation as read');
    });
  });

  // ── list_reactions ──────────────────────────────────────────────────

  describe('talk_list_reactions', () => {
    it('should group reactions by emoji with actors', async () => {
      mockOCS({
        '👍': [
          { actorId: 'alice', actorDisplayName: 'Alice' },
          { actorId: 'bob', actorDisplayName: 'Bob' },
        ],
        '❤️': [{ actorId: 'carol', actorDisplayName: 'Carol' }],
      });

      const { listReactionsTool } = await import('../tools/apps/talk.js');
      const result = await listReactionsTool.handler({ token: 'abc123', messageId: 42 });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('Reactions on message 42 (2 emoji)');
      expect(result.content[0].text).toContain('👍: Alice, Bob');
      expect(result.content[0].text).toContain('❤️: Carol');
    });

    it('should report no reactions when the map is empty', async () => {
      mockOCS({});

      const { listReactionsTool } = await import('../tools/apps/talk.js');
      const result = await listReactionsTool.handler({ token: 'abc123', messageId: 42 });

      expect(result.content[0].text).toContain('No reactions on message 42');
    });

    it('should pass a reaction filter as a query parameter', async () => {
      mockOCS({});

      const { listReactionsTool } = await import('../tools/apps/talk.js');
      await listReactionsTool.handler({ token: 'abc123', messageId: 42, reaction: '👍' });

      const url = String((global.fetch as ReturnType<typeof vi.fn>).mock.calls[0][0]);
      expect(url).toContain('reaction=');
      expect(url.split('?')[0]).toBe(
        'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/reaction/abc123/42'
      );
    });
  });

  // ── remove_reaction ─────────────────────────────────────────────────

  describe('talk_remove_reaction', () => {
    it('should remove the user reaction and report remaining ones', async () => {
      mockOCS({
        '👍': [{ actorId: 'alice', actorDisplayName: 'Alice' }],
      });

      const { removeReactionTool } = await import('../tools/apps/talk.js');
      const result = await removeReactionTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '❤️',
      });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('Reaction "❤️" removed from message 42');
      expect(result.content[0].text).toContain('👍: Alice');

      const call = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(String(call[0])).toBe(
        'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/reaction/abc123/42'
      );
      expect((call[1] as RequestInit).method).toBe('DELETE');
      expect((call[1] as RequestInit).body).toBe(JSON.stringify({ reaction: '❤️' }));
    });

    it('should report when no reactions remain', async () => {
      mockOCS({});

      const { removeReactionTool } = await import('../tools/apps/talk.js');
      const result = await removeReactionTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.content[0].text).toBe('Reaction "👍" removed from message 42.');
    });

    it('should surface not-found when the user never reacted', async () => {
      mockAPIError(404);

      const { removeReactionTool } = await import('../tools/apps/talk.js');
      const result = await removeReactionTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing reaction');
    });
  });

  // ── Network errors ──────────────────────────────────────────────────

  describe('network errors', () => {
    it('should handle network errors gracefully', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });
});
