# Docker Development Environment

Complete guide for setting up and using the ReQurv Hive Docker development environment.

## Overview

The Docker environment provides a complete, isolated development setup with:

- **Caddy 2** - Reverse proxy with automatic HTTPS (self-signed certs for dev)
- **PostgreSQL 18** - Production-grade database
- **Nextcloud 34** - Test instance with ReQurv Hive pre-installed from tarball
- **Redis 7** - Caching and performance
- **MCP Server** - Development container with hot reload (HTTP transport on port 3339)
- **MailHog** - Email testing and debugging
- **Adminer** - Database management UI

The ReQurv Hive app is installed automatically on first start from a pre-built tarball. No manual setup wizard or app installation is needed.

> **Standalone mode?** If you already have a Nextcloud instance and only need the MCP server, see [Standalone Docker Setup](../mcp/standalone-docker.md).

## Quick Start

### Prerequisites

- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB+ RAM available for Docker
- **Linux:** Ensure your user is in the `docker` group

### Setup

```bash
cd docker/installation

# Copy environment template and add your API key
cp .env.example .env
nano .env   # set HIVE_API_KEY

# Build the ReQurv Hive app tarball
make build-tarball

# Start all services
make up
```

On first run, Docker downloads images (~2-3 GB) and the entrypoint script automatically:
1. Waits for Nextcloud to initialize
2. Extracts and installs the ReQurv Hive app from the tarball
3. Creates the test user
4. Configures the AI provider API key
5. Enables debug mode

Watch progress:
```bash
make logs-follow
```

### Access Points

| Service | HTTPS (via Caddy) | Direct HTTP | Credentials |
|---------|-------------------|-------------|-------------|
| **Nextcloud** | https://localhost | http://localhost:8080 | admin / admin123 |
| **MCP Server** | https://localhost:3340/mcp | http://localhost:3339/mcp | — |
| **MailHog UI** | — | http://localhost:8025 | — |
| **Adminer** | — | http://localhost:8081 | See below |

Caddy uses self-signed certificates — expect a browser warning on first visit.

**Adminer:** System `PostgreSQL` · Server `db` · User `nextcloud` · Password from `.env` · Database `nextcloud`

## Development Workflow

### Updating the Nextcloud App

The Nextcloud app is installed from a tarball baked into the Docker image. To pick up PHP source changes:

```bash
make build-tarball   # rebuild tarball from source
make build           # rebuild Docker image
make up              # restart with new image
```

### MCP Server (Live Reload)

The MCP server source (`mcp-server/`) is volume-mounted into the container. Changes are picked up automatically via `tsx` hot reload — no rebuild needed.

```bash
make logs-mcp   # watch MCP server output
```

### Common Commands

```bash
make up            # start all services
make down          # stop all services
make restart       # restart all services
make status        # show service status
make logs-follow   # follow all logs
make logs-nc       # Nextcloud logs
make logs-mcp      # MCP server logs
make shell         # shell in Nextcloud container
make shell-mcp     # shell in MCP container
make shell-db      # PostgreSQL shell
make test          # verify ReQurv Hive installation
make test-mcp      # run MCP server tests
make clean         # stop + remove containers (keeps volumes)
make reset         # full reset — rebuilds tarball + image from scratch
```

### occ Commands

```bash
make shell   # then inside the container:

php occ app:list
php occ app:enable requrvhive
php occ user:list
php occ config:app:get requrvhive
php occ requrvhive:configure --api-key="sk-ant-..."
```

### Database Access

Via Adminer at http://localhost:8081, or:

```bash
make shell-db

# Example
\dt
SELECT * FROM oc_appconfig WHERE appid='requrvhive';
\q
```

### Email Testing

All Nextcloud emails are caught by MailHog. View them at http://localhost:8025.

## Configuration

### Environment Variables

Edit `docker/installation/.env`:

```bash
POSTGRES_PASSWORD=nextcloud_secure_password
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=admin123
NEXTCLOUD_TRUSTED_DOMAINS=localhost localhost:8080
NEXTCLOUD_TEST_USER=testuser
NEXTCLOUD_TEST_PASSWORD=testpass123
HIVE_API_KEY=sk-hive-xxxxx
```

After changing `.env`, restart: `make down && make up`

### Port Conflicts

Edit `docker/installation/docker-compose.yml` to change port mappings if needed.

## Testing MCP Server Integration

```bash
# Check MCP server is responding
curl -X POST http://localhost:3339/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'

# Test Nextcloud connectivity from inside MCP container
make shell-mcp
curl -u testuser:testpass123 http://nextcloud/remote.php/dav/files/testuser/
```

The MCP server uses `MCP_TRANSPORT=http` in Docker. For local development outside Docker, stdio is the default.

## Troubleshooting

### Services won't start
```bash
make status
make logs
make down && make up
```

### ReQurv Hive app not installed
Check the entrypoint logs — the tarball may be missing or corrupt:
```bash
make logs-nc
# Look for [INSTALL] and [VERIFY] lines

# Rebuild tarball and restart
make reset
```

### MCP server can't connect to Nextcloud
```bash
make shell-mcp
env | grep NEXTCLOUD
curl -u testuser:testpass123 http://nextcloud/status.php
```

### Permission errors
```bash
make shell
chown -R www-data:www-data /var/www/html
```

### Database connection errors
```bash
docker compose ps db
docker compose exec db pg_isready -U nextcloud
docker compose logs db
```

## Data Management

Data is stored in named Docker volumes that persist between restarts:

- `requrvhive_postgres_data` — database
- `requrvhive_redis_data` — cache
- `requrvhive_nextcloud_data` — Nextcloud files

```bash
# Backup database
docker compose exec db pg_dump -U nextcloud nextcloud > backup.sql

# Restore database
cat backup.sql | docker compose exec -T db psql -U nextcloud nextcloud

# Full reset (DESTRUCTIVE — deletes all data)
make reset
```

> **Upgrading PostgreSQL major versions:** the stack ships `postgres:18`. A major upgrade
> won't read an older major's data volume automatically — dump before bumping the image and
> restore afterwards.
>
> Note that `postgres_data` is mounted at `/var/lib/postgresql` (not `/var/lib/postgresql/data`),
> because `postgres:18` stores its data in a major-version subdirectory and refuses to start
> against the older mount point. A volume from a stack that predates this cannot be reused
> in place.

## Architecture

```
┌───────────────────────────────────────────────────────────┐
│                      Docker Host                          │
│                                                           │
│  ┌────────────────┐                                       │
│  │  Caddy (HTTPS) │                                       │
│  │  :443  :3340   │                                       │
│  └───┬────────┬───┘                                       │
│      │        │                                           │
│      ▼        ▼                                           │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │  Nextcloud   │    │  MCP Server  │                     │
│  │  :8080       │    │  :3339       │                     │
│  │  + ReQurv Hive   │◄───│  (HTTP/SSE)  │                     │
│  │  (tarball)   │    │  (src mount) │                     │
│  └──────┬───────┘    └──────────────┘                     │
│         │                                                 │
│  ┌──────┴───────┐    ┌──────────────┐                     │
│  │  PostgreSQL  │    │    Redis     │                     │
│  └──────────────┘    └──────────────┘                     │
│                                                           │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │   MailHog    │    │   Adminer    │                     │
│  │   :8025      │    │   :8081      │                     │
│  └──────────────┘    └──────────────┘                     │
└───────────────────────────────────────────────────────────┘
```

For more help, see the [Development Guide](development.md) or [GitHub Issues](https://github.com/ReQurv/nextcloud-plugin-hive/issues).
