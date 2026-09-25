# ReQurv Hive Internal API

ReQurv Hive provides a public API that other Nextcloud apps can use to integrate Claude AI functionality.

## Overview

The ReQurv Hive service can be used from:
- Other Nextcloud apps
- Background jobs and workflows
- OCC commands
- Nextcloud Talk bots
- Any PHP code running within Nextcloud

## Getting Started

### Basic Usage

```php
<?php
// Get the ReQurv Hive service
$requrvhive = \OC::$server->get(\OCA\RequrvHive\Public\IRequrvHive::class);

// Check if configured
if ($requrvhive->isConfigured()) {
    // Ask Claude a question
    $result = $requrvhive->ask(
        'What is the capital of France?',
        '',  // optional context
        'admin'  // user ID
    );

    if (isset($result['response'])) {
        echo $result['response'];  // "The capital of France is Paris."
    } else {
        echo 'Error: ' . $result['error'];
    }
}
```

## API Reference

### `ask(string $prompt, string $context = '', ?string $userId = null): array`

Ask Claude AI a question with optional context.

**Parameters:**
- `$prompt` (string) - The question to ask Claude
- `$context` (string, optional) - Additional context (e.g., file content, background info)
- `$userId` (string|null, optional) - User ID for user-specific API key, null for admin key

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$result = $requrvhive->ask(
    'Summarize this document',
    file_get_contents('/path/to/document.txt'),
    'user123'
);
```

### `summarize(string $content, ?string $userId = null): array`

Summarize content using Claude AI.

**Parameters:**
- `$content` (string) - Content to summarize
- `$userId` (string|null, optional) - User ID for user-specific API key

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$longText = "...very long document...";
$result = $requrvhive->summarize($longText, 'user123');
```

### `analyzeFile(string $filePath, string $prompt, ?string $userId = null): array`

Analyze a Nextcloud file with Claude AI.

**Parameters:**
- `$filePath` (string) - Nextcloud file path (e.g., `/Documents/report.pdf`)
- `$prompt` (string) - What to ask about the file
- `$userId` (string|null, optional) - User ID who owns/can access the file

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$result = $requrvhive->analyzeFile(
    '/Documents/Q4-Report.pdf',
    'What are the key findings in this report?',
    'user123'
);
```

### `isConfigured(?string $userId = null): bool`

Check if ReQurv Hive is configured and ready to use.

**Parameters:**
- `$userId` (string|null, optional) - User ID to check for user-specific configuration

**Returns:**
- `bool` - True if an API key is configured (either user or admin level)

**Example:**
```php
if ($requrvhive->isConfigured('user123')) {
    // API is ready to use
} else {
    // Show configuration instructions
}
```

### `getStatus(): array`

Get current ReQurv Hive configuration status.

**Returns:**
- `array` - Configuration information:
  ```php
  [
      'configured' => bool,      // Whether an API key is set
      'model' => string,         // Claude model (e.g., 'claude-opus-5', 'claude-sonnet-5')
      'max_tokens' => int,       // Maximum tokens (1-100000)
      'timeout' => int           // API timeout in seconds (10-1800)
  ]
  ```

**Example:**
```php
$status = $requrvhive->getStatus();
echo "Model: {$status['model']}\n";
echo "Max Tokens: {$status['max_tokens']}\n";
```

### `askAsync(string $prompt, string $context, string $userId, bool $notify = true): array`

Process a Claude request asynchronously (for long-running operations).

Useful for large documents or complex analysis that might timeout. User will receive a notification when complete.

**Parameters:**
- `$prompt` (string) - The prompt to send to Claude
- `$context` (string) - Optional context
- `$userId` (string) - User ID to notify on completion
- `$notify` (bool, optional) - Whether to send notification (default: true)

**Returns:**
- `array` - Returns `['status' => 'queued', 'message' => string]` or error

**Example:**
```php
$result = $requrvhive->askAsync(
    'Analyze this large dataset',
    file_get_contents('/path/to/large-file.csv'),
    'user123',
    true  // send notification
);
```

## Use Cases

### 1. Document Analysis in Files App

```php
// In your app's file action handler
$requrvhive = \OC::$server->get(\OCA\RequrvHive\Public\IRequrvHive::class);

$fileContent = $this->readFile($filePath);
$result = $requrvhive->ask(
    'Summarize the main points of this document',
    $fileContent,
    $userId
);

// Display result to user
return new JSONResponse($result);
```

### 2. Nextcloud Talk Bot

```php
// In a Talk bot message handler
$requrvhive = \OC::$server->get(\OCA\RequrvHive\Public\IRequrvHive::class);

if ($requrvhive->isConfigured()) {
    $response = $requrvhive->ask($userMessage, '', $userId);
    $this->sendTalkMessage($response['response']);
}
```

### 3. Workflow Integration

```php
// In a workflow app
$requrvhive = \OC::$server->get(\OCA\RequrvHive\Public\IRequrvHive::class);

// Analyze uploaded document
$result = $requrvhive->summarize($documentContent, $userId);

// Tag document based on summary
if (isset($result['response'])) {
    $this->tagDocument($documentId, $result['response']);
}
```

### 4. Background Job

```php
<?php
namespace OCA\MyApp\BackgroundJob;

use OCA\RequrvHive\Public\IRequrvHive;
use OCP\BackgroundJob\QueuedJob;

class AnalyzeDocumentJob extends QueuedJob {
    protected function run($argument) {
        $requrvhive = \OC::$server->get(IRequrvHive::class);

        $result = $requrvhive->ask(
            'Analyze this document: ' . $argument['prompt'],
            $argument['content'],
            $argument['userId']
        );

        // Store or process result
    }
}
```

## Configuration

### Admin Configuration

Administrators can configure ReQurv Hive via:

**Web UI:**
- Settings → Administration → ReQurv Hive
- The **Providers** tab has one card per provider; open a card, enter its key
  and model, then click **Test connection** to send a live request.

**OCC Command:**
```bash
php occ requrvhive:configure --api-key "sk-ant-..." \
  --model "claude-sonnet-4-5-20250929" \
  --max-tokens 8192 \
  --timeout 60
```

**Testing Configuration:**
```bash
# Test with default prompt
php occ requrvhive:doctor

# Test with custom prompt
php occ requrvhive:doctor --prompt "Hello, Claude!"

# Test with specific user
php occ requrvhive:doctor --user john
```

### User Configuration

Users configure their own provider, key and model in
**Settings → Personal → ReQurv Hive**. A personal key overrides the instance key;
leaving a field blank inherits the instance setting.

Anything a user may not set — notably provider endpoint URLs — is admin-only and
is not offered on the personal page.

### Provider resolution

`ask()`, `summarize()` and friends resolve the provider per call: a user
override (`user_provider`) wins over the instance default (`provider`).

The chat controller adds one more level: a conversation may pin a provider and
model of its own, so it keeps answering from where it started even after the
user changes their default. `LLMProviderFactory::isKnownProviderId()` is the
guard to use before acting on any provider id that came from a request —
`getProviderById()` deliberately falls back to Anthropic for unknown ids, which
is right for a stale config value but would mask a bad request.

### Provider access control

Each provider carries four lists an admin edits under **Access** on its card in
the admin settings: allowed users, allowed groups, blocked users, blocked
groups. They are stored in `requrvhive_provider_access` (one row per principal) and
interpreted by `ProviderAccessService`:

- An empty allow-list means **everyone**.
- **A block always wins**, whether it names the user or one of their groups.
- A `null` user id — CLI, background job, admin settings — is unrestricted.

Every level of provider resolution runs through the check, including the
instance default: if the default is blocked for a user, resolution falls through
to the first provider they may actually use rather than handing back one they
cannot. If nothing is left, `getActiveProviderId()` throws
`NoPermittedProviderException` and the endpoints answer **403** — provider
resolution fails closed rather than serving a blocked provider.

Two methods matter for callers:

- `isAllowedForUser($id, $userId)` — check before persisting a provider id that
  came from a request (conversation pin, coworker pin, personal settings).
- `getProviderForUser($userId, $pinnedId)` — resolve a *stored* pin. Permissions
  can be revoked after a pin is made, so a pin the user may no longer use
  degrades to their current provider instead of continuing to be honoured. Use
  this rather than `getProviderById()` on any stored id.

`GET /api/admin/principals?search=` backs the user/group pickers; it is
admin-only.

## Error Handling

Always check for errors in the response:

```php
$result = $requrvhive->ask($prompt, $context, $userId);

if (isset($result['error'])) {
    // Handle error
    \OCP\Util::writeLog('myapp', 'ReQurv Hive error: ' . $result['error'], \OCP\Util::ERROR);

    if ($result['error'] === 'No API key configured') {
        // Prompt user to configure API key
    } else if (strpos($result['error'], 'Rate limit') !== false) {
        // Handle rate limit
    } else {
        // Generic error handling
    }
} else {
    // Use response
    $response = $result['response'];
}
```

## Rate Limiting

ReQurv Hive implements rate limiting:
- **10 requests per minute** per user
- Returns `429` status code when exceeded
- Error message: "Rate limit exceeded. Maximum 10 requests per minute."

Handle rate limits gracefully in your app:

```php
if (isset($result['error']) && strpos($result['error'], 'Rate limit') !== false) {
    // Wait and retry, or show user-friendly message
    sleep(60);  // Wait 1 minute
    $result = $requrvhive->ask($prompt, $context, $userId);
}
```

## Content Size Limits

- Maximum content size: **5MB** (5,242,880 bytes)
- Includes prompt + context combined
- Error: "Content too large. Maximum size is 5MB"

For large files, consider:
1. Chunking content
2. Using async processing
3. Extracting key sections only

## Security Considerations

1. **API Keys:**
   - Both admin and user keys are stored via `ICredentialsManager` (encrypted at rest)
   - MCP server tokens are encrypted via `ICrypto` before database storage
   - Never expose API keys in responses

2. **User Context:**
   - Always pass `$userId` when processing user data
   - Respects user-specific API keys
   - Logs requests with user context

3. **Input Validation:**
   - Content length validated automatically
   - Rate limiting prevents abuse
   - All inputs sanitized before sending to Claude

## Logging

ReQurv Hive logs all requests:

```bash
# View logs
sudo -u www-data tail -f /var/www/nextcloud/data/nextcloud.log | grep ReQurv Hive

# Or via OCC
php occ log:watch | grep ReQurv Hive
```

Log levels:
- `INFO` - Successful requests
- `ERROR` - API errors, exceptions
- `DEBUG` - Detailed request/response data (when debug mode enabled)

## Testing

Test the API in your app:

```php
// Check if available
$requrvhive = \OC::$server->query(\OCA\RequrvHive\Public\IRequrvHive::class);
if ($requrvhive === null) {
    throw new \Exception('ReQurv Hive app not installed');
}

// Check if configured
if (!$requrvhive->isConfigured()) {
    throw new \Exception('ReQurv Hive not configured');
}

// Simple test
$result = $requrvhive->ask('Test: respond with OK', '', 'admin');
assert(isset($result['response']));
```

## Support

For issues or questions:
- GitHub: https://github.com/ReQurv/nextcloud-plugin-hive
- Nextcloud Forum: https://help.nextcloud.com
- Documentation: https://github.com/ReQurv/nextcloud-plugin-hive/tree/main/docs
