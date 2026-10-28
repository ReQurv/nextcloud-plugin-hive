# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/).

## [Unreleased]

## 0.6.1 – 2026-10-28
### Added
- Talk bot: `/thinking` command — shows the room's thinking state, and (room owner) `/thinking on|off` toggles the model's reasoning pass per room for much faster answers
- The Hive provider honours the per-conversation thinking override: with thinking off it sends `enable_thinking: false` to the model instead of `reasoning_effort`

## 0.6.0 – 2026-10-24
### Added
- Talk bot for Nextcloud: ReQurv Hive can now answer directly in your Talk rooms. Each room is bound to a conversation, the binding can be re-pointed to the room owner, and the bot understands the `/help`, `/new` and `/model` commands
- `occ requrvhive:probe` — inspect provider / model-server responses (including 4xx/5xx) from the command line, with `--replay` to replay known request shapes

### Fixed
- The AI chat no longer returns a 502 when the assistant calls a tool that takes no arguments (e.g. listing calendars). Such calls were sent as `arguments: "[]"` (a JSON array) instead of `"{}"`, which the model's chat template rejected
- A calendar or date range without a single VEVENT no longer causes a 500
- Provider calls that hit a transient failure (a 5xx response or a dropped connection) are now retried with exponential backoff instead of failing immediately

## 0.5.5 – 2026-10-22
### Added
- Restored the missing MIT `LICENSE` at the repository root, preserving the original copyright notice for AIquila (the MCP server and repository are MIT-licensed)

### Changed
- Restored provenance and attribution: ReQurv Hive is now clearly documented as based on [AIquila](https://github.com/elgorro/aiquila) by sirgorro (AGPL-3.0-or-later / MIT), with a prominent notice of modification and date, as required by the upstream licences (AGPL-3.0 §5(a))
- Added the original author (elgorro) to the `<author>` entries in `appinfo/info.xml` and to the App Store description

## 0.5.4 – 2026-10-16
### Fixed
- App Store still showed the old (eagle) icon: the store caches the screenshot by URL and the previous URL pointed at a mutable `main`-branch file, so the icon updated in 0.5.2 was never re-fetched. The screenshot now points at the immutable `nc-v0.5.3` tag, forcing the store to pick up the current logo

## 0.5.3 – 2026-10-16
### Fixed
- Enabling the app made DAV and OCS endpoints return 500 errors on Nextcloud 33/34. The app bundled its own `sabre/vobject` (pulling `sabre/xml` 4.x), whose classes shadowed the server's older `3rdparty` Sabre stack via the prepended Composer autoloader, causing hard PHP fatals on every webdav/OCS request. The app now uses the Sabre VObject copy shipped with Nextcloud and its autoloader no longer prepends, so server 3rdparty classes always take precedence
- `RepairEncryptMcpTokens` repair step: removed dead code and made the plaintext/encrypted detection logic explicit

## 0.5.2 – 2026-10-13
### Changed
- Updated App Store icon

## 0.5.1 – 2026-10-13
### Fixed
- App package now ships `CHANGELOG.md`

## 0.5.0 – 2026-10-04
### Added
- Built-in Calendar, Mail and Notes tools for the AI chat: the assistant can list calendars and events, create events, browse mailboxes and messages, and read or create notes on its own — no external MCP server required. Each tool group is offered only while the Nextcloud app that backs it (Calendar, Mail, Notes) is enabled on the instance
- `sabre/vobject` dependency for calendar parsing

### Changed
- ReQurv AI Hive provider: default API endpoint is now `https://hive.requrv.ai/api/v1`
- ReQurv AI Hive provider: sends `reasoning_effort: low` (via the `extra_body` pass-through) for reasoning-capable models such as `requrv-small-3.8`

## 0.0.1 – 2026-09-25
### Added
- First release of ReQurv Hive for Nextcloud
- Chat with a private AI assistant over your Nextcloud files and documents
- Projects — scope a conversation to selected folders
- Coworkers — save repeatable AI jobs, run on demand or on a schedule
- Summarise, rewrite, rephrase and proofread text
- Model picker per conversation
- OpenAI-compatible provider (ReQurv AI Hive) with admin-configured, encrypted API key
- MCP server with 316 tools exposing Nextcloud to any MCP client
- Usage metrics exported via OpenMetrics
- Support for Nextcloud 33–35 (PHP 8.4–8.5)
