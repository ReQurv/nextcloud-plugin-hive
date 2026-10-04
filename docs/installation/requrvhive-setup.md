# ReQurv Hive — Nextcloud App Setup

Complete guide to installing and configuring the ReQurv Hive Nextcloud app.

## Prerequisites

- Nextcloud 34 — see [Nextcloud compatibility](../nextcloud-compatibility.md) for the supported version window
- PHP 8.4 or higher with Composer
- Node.js 26 or higher (for building frontend)
- npm 10 or higher
- A ReQurv AI Hive API key — see [Registering for the ReQurv AI Hive](#registering-for-the-requrv-ai-hive)

## Registering for the ReQurv AI Hive

All model calls made by the plugin are served by the [ReQurv AI Hive](https://hive.requrv.ai),
ReQurv's private and secure OpenAI-compatible inference endpoint. Before the app can answer,
you need an API key:

1. **Create an account** — go to [hive.requrv.ai](https://hive.requrv.ai) and sign up.
2. **Generate an API key** — from your account dashboard.
3. **Add the key to Nextcloud** — as an admin, open **Settings → Administration → ReQurv
   Hive**, paste the API key and use **Test connection** to confirm it works.

The key is an instance-level credential: it is stored encrypted in Nextcloud's credential
manager and shared by every user on the instance. Users cannot set a personal key.

## What You Get

ReQurv Hive provides five main features:

1. **Chat Interface**: Interactive chat with the ReQurv AI Hive at `/apps/requrvhive`
2. **Projects**: Scope a conversation to selected folders, so the assistant only sees what you choose
3. **Coworkers**: Save repeatable AI jobs and run them on demand or on a schedule
4. **TaskProcessing Provider**: Native integration with the Nextcloud Assistant
5. **Public API**: RESTful endpoints for other apps to use ReQurv Hive

## Installation

### 1. Install Dependencies

```bash
cd nextcloud-app

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Build frontend
npm run build
```

### 2. Deploy the App

Choose one of these deployment methods:

**Option A: Copy to Nextcloud (Production)**
```bash
cp -r nextcloud-app /path/to/nextcloud/custom_apps/requrvhive
```

**Option B: Symlink (Development)**
```bash
ln -s /path/to/requrvhive/nextcloud-app /path/to/nextcloud/custom_apps/requrvhive
```

**Option C: Docker Development**
```bash
# See docs/dev/docker-setup.md for complete Docker development environment
```

### 3. Set Correct Permissions

The web server needs to read all app files:

```bash
cd /path/to/nextcloud/custom_apps/requrvhive

# Quick fix: set all permissions recursively
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

**Important files that need 644 permissions:**
- `lib/**/*.php` - PHP classes
- `templates/*.php` - Templates
- `css/*.css` - Stylesheets
- `js/*.js` - JavaScript files
- `img/*.svg` - Icons

### 4. Enable the App

**Via command line (recommended):**
```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable requrvhive
```

**Via web interface:**
1. Go to **Settings → Apps**
2. Find "ReQurv Hive" in the disabled apps list
3. Click **Enable**

### 5. Configure the ReQurv AI Hive

**Admin configuration** (applies to every user):

1. Navigate to **Settings → Administration → ReQurv Hive**
2. The **Providers** tab shows the ReQurv AI Hive card — whether it is configured,
   which model it will use, and what it can do (vision).
3. Click **Configure** to open it:
   - **API key** — from [hive.requrv.ai](https://hive.requrv.ai). It is an
     instance-level credential: stored encrypted in Nextcloud's credential manager
     and shared by every user.
   - **Default model** — the live model list from the endpoint, with
     **Refresh models** to re-query it.
   - **Advanced** — max output tokens (default 8192), request timeout (default 30s),
      and the **API endpoint**. Leave the endpoint blank to use
      `https://hive.requrv.ai/api/v1`; the override is admin-only, because the server makes
      outbound requests to whatever is configured there.
4. Click **Save**, then **Test connection** to send a live request and confirm the
   key reaches the endpoint.

The other tabs cover instance defaults (unified search), **MCP servers**, and
**Advanced**. Each tab is linkable by its anchor,
e.g. `Settings → Administration → ReQurv Hive#mcp`.

**Personal configuration** (optional):

1. Go to **Settings → Personal → RequrvHive**
2. The **Providers** tab lets you pick the model your conversations use by default.
   The API key is instance-level and cannot be overridden per user.
3. **Defaults** sets the system prompt and verbose mode new conversations start
   with, and holds the two notification toggles: completed AI tasks are silent
   unless you switch them on, failed ones are reported by default.

**Command line** (headless provisioning):

```bash
php occ requrvhive:configure --api-key 'your-key' --model requrv-small-3.8
php occ requrvhive:configure --show
```

`occ requrvhive:doctor` checks the app status, the registered TaskProcessing providers
and the API key, and prints a step-by-step diagnosis.

### 6. Pick a model per conversation

The model is snapshotted when a conversation is created, so changing your default
afterwards leaves existing conversations answering from where they started.

The picker in the chat header changes the model for the open conversation only.
Choosing **Follow my default** unpins the conversation so it tracks your personal
setting again.

## Features

### 1. Chat Interface

Access at **`/apps/requrvhive`**

Features:
- Interactive conversation with the ReQurv AI Hive
- Conversation history with search
- File and image attachments (see below)
- Slash commands (`/add-file`, `/add-directory`, `/verbose`, `/search`, and more)
- Markdown rendering in responses
- Clean, responsive design
- Per-conversation model picker in the header
- Settings gear linking to your personal RequrvHive settings

Usage:
1. Navigate to `/apps/requrvhive`
2. Type your question in the text area
3. Press **Enter** or click **Send**
4. See the response appear in the chat history

#### Attaching Files

You can attach files to your message so the assistant can read or analyze them:

| Method | How |
|--------|-----|
| **Attachment button** | Click the 📎 button next to Send to open the Nextcloud file picker |
| **Slash command** | Type `/add-file` and press Enter to open the file picker (multi-select) |
| **Directory context** | Type `/add-directory` to attach a directory — the whole file tree is sent with the message and stays available for the rest of the conversation, so every file inside it (up to size and count limits) can be read and discussed without attaching it separately |
| **Drag & drop** | Drag files from your desktop or browser into the chat input area |
| **Clipboard paste** | Press `Ctrl+V` to paste an image from your clipboard |

Attached files appear as chips above the text input (with thumbnails for images). Pasted and dropped images are automatically uploaded to the `/RequrvHive Uploads` folder in your Nextcloud files.

### 2. Nextcloud Assistant Integration

ReQurv Hive registers itself against Nextcloud's TaskProcessing framework, so it shows
up wherever the server offers an AI action — the Assistant, the Files and Photos context
menus, Text, Talk and anything else built on the same API. No extra configuration is
needed.

| Task type | What it does |
|---|---|
| `core:text2text` | Free-form prompt |
| `core:text2text:chat` | Multi-turn chat, with history and a system prompt |
| `core:text2text:chatwithtools` | Multi-turn chat where the caller supplies the tools and runs them |
| `core:text2text:summary` | Summarize |
| `core:text2text:headline` | Suggest a headline |
| `core:text2text:topics` | Extract topics |
| `core:text2text:translate` | Translate |
| `core:text2text:proofread` | Proofread |
| `core:text2text:changetone` | Change tone |
| `core:text2text:simplification` | Simplify |
| `core:text2text:reformulation` | Reformulate |
| `core:text2text:formalization` | Make formal |
| `core:text2text:reformatparagraphs` | Split into topic-separated paragraphs (Nextcloud 34+) |
| `core:contextwrite` | Write about a subject in the voice of a sample |
| `core:generateemoji` | Suggest an emoji for a text |
| `core:analyze-images` | Ask a question about one or more images |
| `core:image2text:ocr` | Extract the text visible in images |
| `core:audio2text` | Transcribe a recording |
| `core:text2speech` | Read a text out as audio |
| `core:text2image` | Generate images from a description |
| `core:audio2audio:chat` | Voice chat: a spoken question answered with spoken audio |

Assistant actions run on the ReQurv AI Hive — the same model choice that drives chat.
Anything beyond plain text needs the matching capability, shown as a chip on the
provider's card in the RequrvHive settings:

| Action | Needs | ReQurv AI Hive |
|---|---|---|
| Image questions and OCR | `vision` | Offered |
| Transcription, voice chat | `audio in` | Not offered |
| Generated speech, voice chat | `audio out` | Not offered |
| Generated images | `image generation` | Not offered |

An action the provider cannot serve fails with a clear message rather than silently
going somewhere else — the point of ReQurv Hive is that your data goes to one place
you chose.

The Context Agent task types (`core:contextagent:interaction` and
`core:contextagent:audiointeraction`) are not served by ReQurv Hive — install a dedicated
provider app for those.

ReQurv Hive answers these tasks but never starts them, and the app that did — the
Assistant, Files, Mail — normally puts the result in front of you already. So a
successful task notifies nobody unless the user turns it on under
**Settings → Personal → RequrvHive → Defaults**. A failed one does notify by default:
it usually means ReQurv Hive itself needs attention, such as a rejected API key, an
exhausted quota or an unreachable endpoint.

### 3. Public API

Other Nextcloud apps can programmatically use ReQurv Hive:

**Ask:**
```http
POST /apps/requrvhive/api/ask
Content-Type: application/json

{
  "prompt": "Your question here",
  "context": "Optional context to provide"
}
```

**Response:**
```json
{
  "response": "The answer...",
  "model": "requrv-small-3.8",
  "usage": {
    "input_tokens": 15,
    "output_tokens": 120
  }
}
```

**Summarize Text:**
```http
POST /apps/requrvhive/api/summarize
Content-Type: application/json

{
  "content": "Long text to summarize..."
}
```

**Response:**
```json
{
  "summary": "Concise summary...",
  "original_length": 5000,
  "summary_length": 150
}
```

See [internal-api.md](../internal-api.md) for complete API documentation.

## Verification

### Quick Tests

1. **Chat Interface**:
   - Go to `/apps/requrvhive`
   - Ask "What is Nextcloud?"
   - Verify you get a response

2. **Admin Test**:
   - Go to **Settings → Administration → ReQurv Hive**
   - Open the ReQurv AI Hive card and click **Test connection**
   - Should see the endpoint's reply inline

3. **Assistant Integration**:
   - Use Nextcloud Assistant anywhere in the UI
   - Select "ReQurv Hive" as the provider
   - Verify it responds to prompts

## Troubleshooting

### Common Issues

#### "Class does not exist" Errors

**Problem:** PHP can't find ReQurv Hive classes

**Solutions:**
```bash
# 1. Install Composer dependencies
cd /path/to/nextcloud/custom_apps/requrvhive
composer install

# 2. Check vendor directory exists
ls -la vendor/

# 3. Verify autoloader was created
ls -la vendor/autoload.php

# 4. Fix file permissions
chmod 644 lib/**/*.php
find lib -type f -exec chmod 644 {} \;
```

#### JavaScript Not Loading

**Problem:** Page loads but interface doesn't appear

**Solutions:**
```bash
# 1. Build the frontend
cd /path/to/nextcloud/custom_apps/requrvhive
npm run build

# 2. Verify build output
ls -la js/requrvhive-main.js

# 3. Check browser console for errors
# Open developer tools (F12) and check Console tab

# 4. Fix JS file permissions
chmod 644 js/*.js
```

#### API Key Not Working

**Problem:** "ReQurv AI Hive rejected the API key" or authentication errors

**Solutions:**
1. Verify the key is valid at [hive.requrv.ai](https://hive.requrv.ai)
2. Check you copied the entire key without extra spaces or newlines
3. Open the ReQurv AI Hive card in **Settings → Administration → ReQurv Hive** and click **Test connection**
4. Check Nextcloud logs:
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log
   ```

#### Network/Connection Errors

**Problem:** "Failed to connect" or timeout errors

**Solutions:**
1. Verify the server can reach `hive.requrv.ai`:
   ```bash
   curl -I https://hive.requrv.ai
   ```
2. Check firewall rules allow HTTPS outbound
3. If using a proxy, configure PHP to use it
4. Raise **Request timeout** under **Advanced** on the provider's card (default: 30s)

#### Permission Errors

**Problem:** "Permission denied" when reading files

**Solution:**
```bash
# Fix all permissions at once
cd /path/to/nextcloud/custom_apps/requrvhive
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Verify web server can read files
sudo -u www-data cat lib/AppInfo/Application.php
```

### Advanced Debugging

**Enable debug mode in Nextcloud:**

Edit `config/config.php`:
```php
'debug' => true,
'loglevel' => 0,
```

**Check Nextcloud logs:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i requrvhive
```

**Test the ReQurv AI Hive API directly** (OpenAI-compatible endpoint):
```bash
curl -X POST https://hive.requrv.ai/api/v1/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "content-type: application/json" \
  -d '{
    "model": "requrv-small-3.8",
    "max_tokens": 100,
    "messages": [{"role": "user", "content": "Hello"}]
  }'
```

## Resources

Need help? Check out these resources:

- 🐝 [ReQurv AI Hive](https://hive.requrv.ai) — the inference endpoint (register here for an API key)
- 🌐 [ReQurv](https://requrv.ai)
- 📦 [GitHub Repository](https://github.com/ReQurv/nextcloud-plugin-hive)
- 📖 [Documentation](https://github.com/ReQurv/nextcloud-plugin-hive/tree/main/docs)
- 🐛 [Report Issues](https://github.com/ReQurv/nextcloud-plugin-hive/issues)
- 💬 [Discussions](https://github.com/ReQurv/nextcloud-plugin-hive/discussions)

## Next Steps

- [MCP Server Setup](../mcp/setup.md) - Connect MCP clients to your Nextcloud
- [Internal API Guide](../internal-api.md) - Integrate ReQurv Hive into your own apps
- [Docker Development](../dev/docker-setup.md) - Set up complete development environment

## Getting Help

If you're still having issues:

1. Search existing [issues](https://github.com/ReQurv/nextcloud-plugin-hive/issues)
2. Ask in [discussions](https://github.com/ReQurv/nextcloud-plugin-hive/discussions)
3. Open a new issue with:
   - Nextcloud version (`Settings → Administration → Overview`)
   - PHP version (`php -v`)
   - Node.js version (`node -v`)
   - Complete error messages from logs
   - Steps to reproduce the problem
