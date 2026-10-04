# Changelog

All notable changes to the ReQurv Hive MCP Server will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/).

## [Unreleased]

## 0.5.0 – 2026-10-02
### Added
17 tools bringing Calendar, Mail, Notes, and Talk to parity with the official Nextcloud MCP server (355 tools total).

**Calendar (5):**
- `get_upcoming_events` — upcoming events in the next N days across all (or one) calendar
- `create_meeting` — quick meeting creation with simple date/time inputs and smart defaults
- `find_availability` — free-slot search over your calendars with business hours, preferred times, and weekend filtering (own calendars only in v1)
- `manage_calendar` — create, delete, and update calendar properties
- `bulk_operations` — update or delete many events matching filter criteria (recurring series skipped by default, safety cap)

**Tasks (1):**
- `search_todos` — search todos across all task lists by status, priority, category, and text

**Mail (4):**
- `mail_create_tag` — create a mail tag or return the existing one (idempotent; the only tag lookup route)
- `mail_set_tag` — assign a tag to a message (creates the tag if needed)
- `mail_remove_tag` — remove a tag from a message
- `mail_get_message_source` — raw RFC 2822 message source including all headers

**Notes (3):**
- `search_notes` — full-text search across all notes, ranked by relevance (title matches weigh 3× content)
- `append_content` — append to a note with a `---` separator
- `get_attachment` — read a note attachment via WebDAV (text, image block, or base64)

**Talk (4):**
- `talk_get_conversation` — conversation details by room token
- `talk_mark_as_read` — move the read marker (all messages or up to a specific message)
- `talk_list_reactions` — reactions on a message grouped by emoji
- `talk_remove_reaction` — remove your own reaction from a message

### Fixed
- All-day events now produce busy spans in availability and bulk filtering (date-only `DTSTART`/`DTEND` parsing)

## [0.4.15 and earlier]

See the git history for changes prior to the introduction of this changelog.
