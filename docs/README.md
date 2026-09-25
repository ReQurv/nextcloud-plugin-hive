# ReQurv Hive Documentation

Complete documentation for the ReQurv Hive Nextcloud app and MCP server.

## Getting Started

**[Getting Started Guide](installation.md)** — four paths to get up and running:
1. **Local MCP Client** — `npx requrvhive-mcp` (simplest — Claude Desktop, Cursor, VS Code, etc.)
2. **Remote MCP Client** — Docker + OAuth (Claude.ai, Cursor, VS Code, etc.)
3. **Nextcloud App** — AI inside Nextcloud UI
4. **Mobile MCP Client** — voice-driven Nextcloud via mobile app

## MCP Server

- **[MCP Overview & Tools Reference](mcp/README.md)** — 342 tools across 44 categories
- **[Setup Guide](mcp/setup.md)** — installation and MCP client configuration
- **[OAuth 2.0](mcp/oauth.md)** — OAuth authentication for remote MCP clients
- **[Standalone Docker](mcp/standalone-docker.md)** — run MCP server in Docker (external Nextcloud)

### Tool Documentation

| Category | Tools | Documentation |
|----------|-------|---------------|
| Files, status, apps, security, search | 25 | [System Tools](mcp/tools/system-tools.md) |
| Calendar events | 6 | [Calendar](mcp/tools/apps/calendar.md) |
| Tasks (CalDAV) | 6 | [Tasks](mcp/tools/apps/tasks.md) |
| Contacts (CardDAV) | 6 | [Contacts](mcp/tools/apps/contacts.md) |
| Email | 8 | [Mail](mcp/tools/apps/mail.md) |
| Bookmarks, folders, tags | 13 | [Bookmarks](mcp/tools/apps/bookmarks.md) |
| Maps, GPS, tracks, photos, contacts | 40 | [Maps](mcp/tools/apps/maps.md) |
| Notes | 5 | [Notes](mcp/tools/apps/notes.md) |
| News (RSS feeds) | 17 | [News](mcp/tools/apps/news.md) |
| Fediverse (Social) | 26 | [Social](mcp/tools/apps/social.md) |
| Recipes | 6 | [Cookbook](mcp/tools/apps/cookbook.md) |
| NC AI tasks & image gen | 4 | [Assistant](mcp/tools/apps/assistant.md) |
| File shares | 4 | [Shares](mcp/tools/apps/shares.md) |
| Users & groups | 8 | [Users](mcp/tools/apps/users.md) / [Groups](mcp/tools/apps/groups.md) |
| ReQurv Hive config | 3 | [ReQurv Hive](mcp/tools/apps/requrvhive.md) |

## Nextcloud App

- **[ReQurv Hive App Setup](installation/requrvhive-setup.md)** — installation, configuration, and troubleshooting
- **[Internal API Guide](internal-api.md)** — integrate ReQurv Hive AI (ask/summarize/analyze) into your own Nextcloud apps
- **[Cowork Management API](cowork-api.md)** — register, steer and verify scheduled cowork jobs from your own Nextcloud app
- **[Monitoring](monitoring.md)** — OpenMetrics / Prometheus export for usage and task metrics
- **[Nextcloud compatibility](nextcloud-compatibility.md)** — supported Nextcloud versions and the policy behind the declared window

## Deployment

- **[Connectivity Guide](connectivity.md)** — network and connection troubleshooting

## Development

- **[Contributing](../CONTRIBUTING.md)** — how to propose a change: claim an issue first, branch and commit conventions, the checks CI runs
- **[Development Guide](dev/development.md)** — local setup, adding tools and endpoints, debugging
- **[Docker Setup](dev/docker-setup.md)** — development environment (`docker/installation/`)
- **[Best Practices](dev/best-practices.md)** — code quality and standards
- **[CI/CD](dev/ci-cd.md)** — continuous integration and deployment
- **[MCP Server Architecture](dev/mcp-server-architecture.md)** — technical design
- **[Provider settings schema](dev/provider-settings.md)** — how providers describe their own configuration, and how to add one
- **[Managed Agents](dev/managed-agents.md)** — why the agentic loop stays in PHP, and what would change that
- **[Built-in chat file tools](dev/chat-file-tools.md)** — how the chat bot reads, searches and views the user's files on its own
- **[Streaming responses](dev/streaming.md)** — how chat replies reach the browser as they are written, and what buffers them
- **[OpenAPI](dev/openapi.md)** — OpenAPI documentation
- **MCP Development** — [Architecture](mcp/development/architecture.md) | [Adding Tools](mcp/development/adding-tools.md) | [Adding Apps](mcp/development/adding-apps.md)

## Documentation Structure

```
docs/
├── README.md                        # This file — navigation hub
├── installation.md                  # Getting started guide
├── nextcloud-compatibility.md       # Supported Nextcloud versions & policy
├── connectivity.md                  # Network & connection troubleshooting
├── internal-api.md                  # Nextcloud app internal AI API
├── cowork-api.md                    # Cowork job management API (ICoworkManager)
│
├── installation/                    # Nextcloud app setup
│   └── requrvhive-setup.md            # Full installation & config guide
│
├── mcp/                             # MCP Server
│   ├── README.md                    # Overview & full tools reference
│   ├── setup.md                     # Setup guide (MCP client / npx)
│   ├── oauth.md                     # OAuth 2.0 for remote MCP clients
│   ├── standalone-docker.md         # Standalone Docker deployment
│   ├── tools/                       # Tool documentation
│   │   ├── system-tools.md          # Files, status, apps, security, search
│   │   └── apps/                    # App-specific tools
│   │       ├── requrvhive.md           # ReQurv Hive config & test
│   │       ├── assistant.md         # NC AI task processing
│   │       ├── bookmarks.md         # Bookmarks, folders, tags
│   │       ├── calendar.md          # Calendar events
│   │       ├── contacts.md          # Contacts via CardDAV
│   │       ├── cookbook.md           # Recipes (schema.org)
│   │       ├── groups.md            # Group management
│   │       ├── mail.md              # Email accounts & messages
│   │       ├── maps.md              # Maps, GPS, tracks, photos
│   │       ├── notes.md             # Markdown notes
│   │       ├── shares.md            # File sharing
│   │       ├── tasks.md             # Tasks via CalDAV
│   │       └── users.md             # User management
│   └── development/                 # MCP development guides
│       ├── architecture.md          # Architecture overview
│       ├── adding-tools.md          # How to add new tools
│       └── adding-apps.md          # How to add new app integrations
│
├── dev/                             # Development documentation
│   ├── docker-setup.md              # Docker dev environment
│   ├── development.md               # Contributing & workflow
│   ├── best-practices.md            # Code quality guidelines
│   ├── ci-cd.md                     # CI/CD setup
│   ├── mcp-server-architecture.md   # MCP technical architecture
│   ├── provider-settings.md         # Provider settings schema
│   ├── managed-agents.md            # Hosted agent loop: evaluation and position
│   ├── chat-file-tools.md           # Built-in file tools the chat offers the model
│   ├── streaming.md                 # Server-Sent Events path and buffering
│   └── openapi.md                   # OpenAPI documentation
```

## Resources

- [ReQurv AI Hive](https://hive.requrv.ai) — the inference endpoint the plugin runs on (register here for an API key)
- [ReQurv](https://requrv.ai)
- [GitHub Repository](https://github.com/ReQurv/nextcloud-plugin-hive)
- [Report Issues](https://github.com/ReQurv/nextcloud-plugin-hive/issues)
