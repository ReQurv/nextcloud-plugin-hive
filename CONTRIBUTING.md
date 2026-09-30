# Contributing to ReQurv Hive

Thank you for your interest in contributing. This guide covers how to set up your environment, follow the project conventions, and submit changes.

## Before You Start

**Open or comment on an issue before writing code.** This lets us confirm the approach and avoid duplicated or rejected work. If no issue exists for what you want to change, open one first.

## Repository Structure

| Directory | Component | Language |
|-----------|-----------|----------|
| `mcp-server/` | MCP Server | TypeScript / Node.js 26 |
| `nextcloud-app/` | Nextcloud App | PHP 8.4–8.5 |
| `docs/` | Documentation | Markdown |
| `docker/` | Container images | Docker |

## Development Setup

### MCP Server

```bash
cd mcp-server
npm ci
npm run build
npm test
```

### Nextcloud App

```bash
cd nextcloud-app
composer install --no-progress --prefer-dist
composer test
```

For a full development environment (Nextcloud instance + app), see [docs/dev/development.md](docs/dev/development.md) and [docs/dev/docker-setup.md](docs/dev/docker-setup.md).

## Branch and Commit Conventions

- Branch names: `<type>/<short-description>`, e.g. `fix/tool-timeout`, `feat/project-scoping`
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):
  - `feat:` — new feature
  - `fix:` — bug fix
  - `docs:` — documentation only
  - `chore:` — maintenance, CI, dependencies
  - `refactor:` — code restructuring without behaviour change
- Scope is optional but encouraged: `feat(mcp): add calendar sync`

## CI Checks

All checks must pass before a PR can be merged.

### Pull requests from forks

CI on forked PRs runs with reduced permissions. Some checks (e.g. release workflows) are skipped. Core lint and test checks still run.

### Checks per component

| Component | Checks |
|-----------|--------|
| MCP Server | ESLint, Prettier, unit tests, build |
| Nextcloud App | PHPUnit, Psalm (static analysis), OpenAPI spec up-to-date |

A PR that touches only Markdown or docs in a component skips that component's test run.

### Local equivalents

```bash
# MCP Server
cd mcp-server
npm run lint
npx prettier --check src/
npm test
npm run build

# Nextcloud App
cd nextcloud-app
composer test
composer psalm
vendor/bin/generate-spec && git diff --exit-code openapi*.json
```

## Submitting a Pull Request

1. Fork the repository and create a branch from `main`.
2. Make your changes, following the style of the surrounding code.
3. Ensure all relevant CI checks pass locally.
4. Open a PR using the [pull request template](.github/PULL_REQUEST_TEMPLATE.md).
5. Fill in the component, description, and link any related issues.

### What to expect

- A maintainer will review within a few days.
- CI must be green on the final commit.
- Squash-merge is used to keep `main` history clean.

## Code Style

- **MCP Server** — ESLint + Prettier (config in `mcp-server/`). Follow the existing patterns in `src/`.
- **Nextcloud App** — `php-cs-fixer` (config in `nextcloud-app/.php-cs-fixer.php`). Follow the existing patterns in `lib/`.
- **Documentation** — Keep it in `docs/`, update `docs/README.md` index if you add a new page.

## License

By contributing, you agree that your contributions will be licensed under the [AGPL-3.0-or-later](LICENSE) license.
