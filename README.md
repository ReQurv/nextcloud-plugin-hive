# ReQurv Hive

Private AI for your Nextcloud — your data, your server, served by the ReQurv AI Hive

## What is ReQurv Hive?

ReQurv Hive brings AI to your self-hosted Nextcloud. Instead of keeping your files, notes, tasks and recipes locked inside Nextcloud — or copying them by hand into a chat window — ReQurv Hive lets an AI assistant read and write your Nextcloud data directly. Your data stays on your own server, and every model call is served by [ReQurv AI Hive](https://hive.requrv.ai), ReQurv's private and secure OpenAI-compatible inference endpoint.

## How it works

ReQurv Hive has three components that can be used independently or together:

**Nextcloud App** — A native Nextcloud application that brings AI directly into the Nextcloud UI. Chat about your documents, summarise and rewrite text, run Coworkers (saved, repeatable AI jobs), and scope a conversation to a Project so the assistant only sees the folders you choose. The admin configures the instance once with a single ReQurv AI Hive API key; each user picks a model per conversation.

**MCP Server** — A [Model Context Protocol](https://modelcontextprotocol.io) server that gives any MCP-compatible AI assistant secure access to your Nextcloud. 316 tools: browse and manage files, keep calendars, tasks and contacts in sync, work in Talk, Deck and Mail, organise photos, notes and bookmarks, and run Coworkers and `occ` administration. It has no model of its own — it exposes Nextcloud to whichever MCP client you connect.

**Hetzner Deployment** — A single-command provisioning tool (`requrvhive-hetzner`) that stands up a production-ready ReQurv Hive server on Hetzner Cloud, complete with Traefik reverse proxy, CrowdSec intrusion prevention, TLS, and optional monitoring.

### AI provider

All model calls are served by a single provider:

**ReQurv AI Hive** — an OpenAI-compatible API hosted by ReQurv at [hive.requrv.ai](https://hive.requrv.ai). The API key is configured once by the admin and stored encrypted in Nextcloud's credential manager; it is shared by every user on the instance. The base URL is an admin-only setting. Provider choice applies to chat, the per-conversation model picker and the Nextcloud Assistant / TaskProcessing integrations alike. Assistant actions that send images ("Analyze images", "Extract text from image") need a vision-capable model.

## Registering for the ReQurv AI Hive

The plugin needs an API key for the [ReQurv AI Hive](https://hive.requrv.ai) endpoint — you get one by registering:

1. **Create an account** — go to [hive.requrv.ai](https://hive.requrv.ai) and sign up.
2. **Generate an API key** — from your account dashboard.
3. **Add the key to Nextcloud** — as an admin, open **Settings → Administration → ReQurv Hive**, paste the API key and use **Test connection** to confirm it works.

Once configured, every user on the instance can use ReQurv Hive immediately — chat, Coworkers and the Assistant integrations all run on the ReQurv AI Hive with no further per-user setup.

## Getting Started

Pick the path that fits your setup:

| Path | What you get | Guide |
|------|-------------|-------|
| `npx requrvhive-mcp` | Local MCP client + Nextcloud | [Quick start](docs/installation.md#path-1-local-mcp-client-simplest) |
| Docker + OAuth | Remote MCP client + Nextcloud | [Quick start](docs/installation.md#path-2-remote-mcp-client-docker--oauth) |
| Nextcloud App | AI inside the Nextcloud UI | [Quick start](docs/installation.md#path-3-nextcloud-app) |
| Hetzner Cloud | Full production deploy | [Quick start](docs/installation.md#path-4-self-hosted-on-hetzner-cloud) |
| Mobile + Voice | Phone + Nextcloud hands-free | [Quick start](docs/installation.md#path-5-mobile-mcp-client-voice) |

- [Getting Started Guide](docs/installation.md) — all five paths with step-by-step instructions
- [Full Documentation](docs/README.md) — architecture, configuration, and advanced topics
- [Nextcloud compatibility](docs/nextcloud-compatibility.md) — which Nextcloud versions are supported, and what "supported" means

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first —
most importantly, **open or comment on an issue before writing code**, so we can
confirm the approach before you invest the effort.

The guide covers branch and commit conventions, the checks CI runs per component,
and what to expect from CI on a pull request from a fork.

## Support

ReQurv Hive is developed by [ReQurv](https://requrv.ai) in the open. For access to
the ReQurv AI Hive endpoint and the plugin itself, start at
[hive.requrv.ai](https://hive.requrv.ai).

Contributing time — issues, pull requests, documentation, or simply telling other
people about the project — helps just as much.

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)

## Credits

ReQurv Hive is published under the AGPL-3.0 (Nextcloud App) and MIT (MCP Server)
licenses.

See [ACKNOWLEDGMENTS.md](ACKNOWLEDGMENTS.md) for the open-source projects and services ReQurv Hive is built on.
