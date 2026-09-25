# ReQurv Hive Internal Tools

Tools for configuring and testing the ReQurv Hive Nextcloud app against the ReQurv AI Hive endpoint.

## Overview

These tools run `requrvhive:*` OCC commands directly on the Nextcloud server through the app's admin-only OCC endpoint. No SSH or Docker access is needed — the MCP server executes the commands with the configured admin account and returns the output.

## Available Tools

### requrvhive_show_config

Show the current ReQurv Hive configuration including API key status, model, tokens, and timeout settings.

**Parameters:**
None

**Returns:**
The output of `occ requrvhive:configure --show` (API key is masked).

**Example Usage:**
```
Ask: "Show my ReQurv Hive configuration"
Ask: "What are my current ReQurv Hive settings?"
```

**Example Output:**
```
RequrvHive Configuration:

  Provider:   hive
  API Key:    (configured)
  Model:      requrv-small-3.8
  Max Tokens: 8192
  Timeout:    30 seconds
```

---

### requrvhive_configure

Configure ReQurv Hive settings: Hive API key, model, max tokens, and API timeout.

**Parameters:**
- `apiKey` (string, optional): ReQurv AI Hive API key (instance-level)
- `model` (string, optional): Hive model identifier (e.g., `requrv-small-3.8`)
- `maxTokens` (number, optional): Maximum tokens for responses (1-128000)
- `timeout` (number, optional): API request timeout in seconds (10-1800)

**Returns:**
The output of `occ requrvhive:configure` with the supplied options.

**Example Usage:**
```
Ask: "Set my ReQurv Hive API key"
Ask: "Update ReQurv Hive max tokens to 8192"
Ask: "Set ReQurv Hive timeout to 120 seconds"
```

**Example with API Key:**
```json
{
  "apiKey": "hive_xxxxxxxxxxxxxxxx"
}
```

**Equivalent OCC Command:**
```bash
php occ requrvhive:configure --api-key "hive_xxxxxxxxxxxxxxxx"
```

**Example with Model:**
```json
{
  "model": "requrv-small-3.8"
}
```

**Equivalent OCC Command:**
```bash
php occ requrvhive:configure --model "requrv-small-3.8"
```

**Example with Multiple Parameters:**
```json
{
  "model": "requrv-small-3.8",
  "maxTokens": 8192,
  "timeout": 120
}
```

**Equivalent OCC Command:**
```bash
php occ requrvhive:configure --model "requrv-small-3.8" --max-tokens 8192 --timeout 120
```

---

### requrvhive_test

Run the ReQurv Hive integration diagnostic (app status, Assistant task providers, API key).

**Parameters:**
None

**Returns:**
The output of `occ requrvhive:doctor`.

**Example Usage:**
```
Ask: "Test my ReQurv Hive integration"
Ask: "Is ReQurv Hive configured correctly?"
```

**Example Output:**
```
RequrvHive diagnostics:
✓ App enabled
✓ Assistant task providers registered
✓ API key configured for provider: hive
```

---

## OCC Command Reference

### Configuration

```bash
# Show current configuration
php occ requrvhive:configure --show

# Set API key
php occ requrvhive:configure --api-key "hive_xxxxxxxxxxxxxxxx"

# Set model
php occ requrvhive:configure --model "requrv-small-3.8"

# Set max tokens
php occ requrvhive:configure --max-tokens 8192

# Set timeout
php occ requrvhive:configure --timeout 120
```

### Diagnostics

```bash
php occ requrvhive:doctor
```

## Configuration Parameters

### API Key
- **Obtaining**: From your [ReQurv AI Hive](https://hive.requrv.ai) account
- **Scope**: Instance-level (one key per Nextcloud instance)
- **Storage**: Encrypted in Nextcloud's credential manager
- **Security**: Never share or commit to version control

### Model
- **Default**: `requrv-small-3.8`

### Max Tokens
- **Range**: 1 - 128,000
- **Default**: 8192

### Timeout
- **Range**: 10 - 1800 seconds
- **Default**: 30 seconds

## Manual Execution

To run the same commands yourself (e.g., from SSH or a Docker host):

```bash
# SSH
sudo -u www-data php occ requrvhive:configure --show

# Docker (container name varies; often nextcloud or requrvhive-nextcloud)
docker exec -u www-data <container> php occ requrvhive:configure --show
```

## Troubleshooting

### Command not found
**Problem**: `requrvhive:configure` or `requrvhive:doctor` not recognized

**Solution**:
- Verify ReQurv Hive app is installed: `php occ app:list | grep requrvhive`
- Enable the app: `php occ app:enable requrvhive`
- Check app version: `php occ app:info requrvhive`

---

### Permission denied
**Problem**: The OCC endpoint rejects the request

**Solution**:
- The MCP server account must be an **admin**
- Check the Nextcloud admin settings for the ReQurv Hive app

---

### API key invalid
**Problem**: API key is rejected by the Hive endpoint

**Solution**:
- Re-register or check your key at [hive.requrv.ai](https://hive.requrv.ai)
- Ensure no extra spaces or quotes
- Re-set the key: `php occ requrvhive:configure --api-key "hive_..."`

---

### Timeout errors
**Problem**: Requests timing out

**Solution**:
- Increase timeout: `php occ requrvhive:configure --timeout 120`
- Check network connectivity to `hive.requrv.ai` (outbound HTTPS)
- Verify firewall allows HTTPS outbound

## Security Best Practices

1. **API Key Storage**:
   - Keys are stored encrypted in Nextcloud's credential manager
   - Never log or display full API keys
   - Rotate keys periodically

2. **Access Control**:
   - Only admin users can configure ReQurv Hive
   - Use strong passwords for the Nextcloud admin account
   - Enable two-factor authentication

3. **Network Security**:
   - All API calls use HTTPS
   - Use firewall to restrict outbound connections

## Development

- Source code: [mcp-server/src/tools/apps/requrvhive.ts](../../../../mcp-server/src/tools/apps/requrvhive.ts)
- Nextcloud app: [nextcloud-app/](../../../../nextcloud-app/)

## References

- [ReQurv AI Hive](https://hive.requrv.ai)
- [Nextcloud OCC Commands](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html)
- [ReQurv Hive Documentation](../../README.md)
