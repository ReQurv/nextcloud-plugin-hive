# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/).

## [Unreleased]

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
