# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest  | Yes       |

ReQurv Hive is actively developed. Security fixes are applied to the latest release only.

## Reporting a Vulnerability

**Do not report security vulnerabilities through public GitHub issues.**

To report a security issue, email the maintainer directly:

- **Email:** info@requrv.io

Your report should include:

- Affected component (MCP Server, Nextcloud App, or both)
- Affected version(s)
- A clear description of the vulnerability
- Steps to reproduce
- Any relevant code or configuration

### What to Expect

- You will receive an acknowledgment within 48 hours.
- We will assess the severity and work on a fix.
- Vulnerabilities are disclosed publicly only after a fix is released.
- Credit will be given in the release notes unless you prefer otherwise.

## Scope

The following are in scope:

- `mcp-server/` — the Model Context Protocol server (TypeScript/Node.js)
- `nextcloud-app/` — the Nextcloud application (PHP)
- `docker/` — container images and installation scripts

The following are out of scope:

- The ReQurv AI Hive inference endpoint (`hive.requrv.ai`) — a separate service with its own security policy
- Third-party dependencies not maintained by this project

## Dependencies

We use [Dependabot](https://docs.github.com/en/code-security/dependabot) to keep dependencies up to date. If you find a vulnerable dependency that Dependabot has not yet flagged, please report it using the process above.

## Nextcloud-Specific Guidance

If you are a Nextcloud administrator concerned about the security of an installed instance, see the [Nextcloud security documentation](https://nextcloud.com/security/) for server-level hardening recommendations.
