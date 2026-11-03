# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/).

## [Unreleased]

## 0.7.1 – 2026-11-03
### Fixed
- Talk bot: a model reply with an empty text (the provider returned no content for the final message) crashed the turn with `Field 'content' doesn't have a default value` and left the room without an answer. An addressed turn now persists a clear model error, and an ambient turn treats the empty reply as silence instead
- The streaming path and file-only messages (empty prompt with attached files) can no longer try to store an empty message body

## 0.7.0 – 2026-11-02
### Added
- Talk bot: ambient mode — with `/auto on` (room owner) the room keeps being answered without an @-mention: the bot takes part in the conversation and the model decides per message whether to answer or stay silent (a `NO_REPLY` answer posts nothing into the room)
- Ambient turns are framed with the room's rolling last-5-messages window (including the bot's own replies), so the model answers the room's flow instead of an isolated line
- One ambient turn per room at a time (the room's ambient lane): a message that arrives while the room is busy is held as a pending catch-up and run after the active turn finishes, bounded to two catch-ups per job
- `/auto` command — shows the room's ambient mode; `/auto on|off` (room owner) toggles it
- Talk bot: answer `@ai` mentions as an alias of `@ReQurv`
- `occ requrvhive:talk:register --rotate-secret` — rotate the stored bot secret and re-register in one step; a single 401 now forces a re-registration and retries the reply once, and `occ requrvhive:doctor` reports the registration state
- Provider settings: remove a stored API key or named secret from the UI, and have `occ requrvhive:configure` accept an empty `--api-key`/`--provider-key` to delete the key
- `occ requrvhive:cleanup [--with-data|--all]` and an UninstallCleanup repair step: disabling the app wipes credentials, settings, preferences, queued jobs and caches, so a reinstall starts clean

### Fixed
- The Talk bot replying with 401 forever after Talk dropped or rewrote the bot row (the stored secret had diverged from Talk's)

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
