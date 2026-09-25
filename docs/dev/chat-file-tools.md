# Built-in chat file tools

Every chat turn offers the model a small set of **built-in tools** over the
user's Nextcloud files, so a question the user's storage can answer does not
depend on an attachment or on any MCP server being configured. The model is
expected to act on them: `ConversationController` appends a short system note
to every turn telling it to locate and read files itself instead of guessing.

The tools are defined and executed by `ChatToolsService`; the MCP tools a user
configured arrive from `McpClientService::getAllTools()`. Both are merged in
`ConversationController::buildChatTools()` and run through the same provider
tool loop (`chatWithTools()` / `chatWithToolsStream()` — see
[Managed Agents](managed-agents.md) for why the loop stays in PHP).

## The tools

| Tool | What it does | Bounds |
|---|---|---|
| `list_directory` | Lists a directory (optionally as a recursive tree) with size, MIME type and modified date per entry, and the path of every entry | 400 entries, depth 4 |
| `get_file_info` | Metadata for one file or folder: name, type, size, MIME, modified date | — |
| `read_file` | Content of a text file | 256 KB default, 1 MB hard cap, truncated with a notice |
| `search_files` | Finds files by name, optionally under a subfolder | 50 results |
| `read_image` | Loads a JPEG/PNG/GIF/WebP for visual analysis | one image per call |

All paths are relative to the **root of the caller's own files** (`/` is the
root): the tools resolve against the requesting user's folder through
`FileService`, so the model can only ever see what that user can see. A
failure (missing path, unreadable file, unknown tool) is returned to the model
as an `isError` tool result — never an exception — so the model can react
inside the same turn, and only a generic message is sent back so internal
server paths never leave the instance.

## Name collisions

The built-in names always win. An MCP tool that collides with one of them is
exposed under an `mcp__<name>` alias, so `read_file` in chat is always the
built-in tool.

## Image results

A tool result is normally text. `read_image` additionally returns an image
block; `AbstractOpenAiCompatibleProvider::executeToolCalls()` keeps it
separate from the text under the block's `images` key. On the OpenAI wire
format a `tool` message is text-only, so on a **vision-capable** backend the
image is handed over as one follow-up `user` message of `image_url` parts; on
a text-only backend the image is dropped and the model keeps the metadata.
The streaming `tool_result` event always carries text only, so the frontend
needs no image handling.
