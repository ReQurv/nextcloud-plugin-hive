# ReQurv Talk bot

ReQurv can answer inside **Nextcloud Talk** rooms. When the bot is present in a
room, anyone who addresses it — by replying to one of its messages, sending a
`/command`, or `@ReQurv`-mentioning it — gets an answer from the ReQurv Hive
model. Each room keeps its own persistent conversation, so context carries over
between messages.

The integration is an **event bot**: there is no public webhook. Talk dispatches
each room message to the app in-process, and the app posts its answer back
through Talk's bot OCS API using a shared secret.

## How it works

The path from a chat message to a reply crosses four pieces:

1. **Registration** — `Talk\TalkBotRegistration` registers ReQurv with Talk and
   owns the bot's identity and secret. It runs cheaply on every app boot
   (`Application::boot()` calls `ensureRegistered()`): a no-op when Talk is
   absent or already registered at the current Talk version, and a re-register
   when the Talk version changes (Talk upgrades rewrite the bot tables).
2. **Invocation** — `Talk\TalkBotListener` listens for Talk's `BotInvokeEvent`
   (registered by name in `Application::registerEventListener()`, so it is a
   no-op when Talk is not installed). It decides whether a message addresses
   ReQurv and, if so, enqueues a `TalkReplyJob`. It never runs the model — an
   agentic LLM call must not block the sender's own request.
3. **The turn** — `BackgroundJob\TalkReplyJob` runs the turn off the request
   path. For a chat message it resolves (creating on first use) the room's
   shared Hive conversation, runs the turn as the room owner, and posts the
   answer back through `Talk\TalkBotClient`. For a `/command` it answers
   locally with no model call.
4. **The reply** — `Talk\TalkBotClient` signs and posts the message into the
   room via the bot OCS API (`X-Nextcloud-Talk-Bot-Random` + an HMAC
   `X-Nextcloud-Talk-Bot-Signature` computed with the shared secret).

### What counts as "addressing the bot"

`TalkBotListener::detectTrigger()` fires on exactly one of:

| Trigger | Condition |
|---|---|
| `reply` | The message is a reply to one of the bot's own messages |
| `command` | The message starts with `/` |
| `mention` | The message contains `@ReQurv` |

Anything else in a room the bot has joined is ignored. Only messages from a
**real user** (a `users/` actor) can drive a turn; guests and other actor
types have no account to run the model under and are skipped.

## Setup

There is nothing to configure. The bot registers itself at boot once Talk is
installed. If you need to force (re)registration — for example after a Talk
upgrade, or to recover a bot row that was removed — run:

```
occ requrvhive:talk:register
```

The command is idempotent (Talk upserts by bot URL) and errors clearly when
Talk is not installed. Once registered, add the **ReQurv** bot to a room from
the room's *Bots* setting, and start talking to it.

## The room is one shared conversation

A Talk room maps to exactly one Hive conversation, created by the **first user
to bring the bot into the room** (a reply, a mention, or a message). That user
becomes the **owner**, and the room's provider is **pinned** to that user's
currently permitted provider, so the room keeps answering from a stable
provider even if the owner later changes their own defaults.

Every later turn — no matter which member triggers it — runs on that same
conversation **as the owner**: the whole room draws on one context, one
provider, and one set of file permissions (the owner's). This is why the
mutating commands below are owner-only — they change the context the entire
room depends on.

If the owner's account is later deleted, the room would otherwise be stuck
running turns as a user that no longer exists. `Talk\TalkContextService`
detects this on the next `getOrCreate()`: it cascade-deletes the stale
conversation (messages, attached files, Context Chat index) and the binding,
and rebinds the room to the user who just triggered it as the new owner. The
room keeps working; its history is reset because it could no longer be owned.

## Commands

Send these to the bot in the room. `/help`, `/model` (no argument), and
chat messages work for everyone in the room; `/new` and `/model <provider>`
are **owner-only**.

| Command | Who | Effect |
|---|---|---|
| `/help` | anyone | Show the command list |
| `/new` | owner | Drop the room's conversation so the next message starts fresh |
| `/model` | anyone | Show the room's current provider and model |
| `/model <provider>` | owner | Switch the room's provider, adopting that provider's default model |

`/model <provider>` checks that the id is a known provider and that the owner
may use it; otherwise it replies with the available providers. An unknown
command replies with a hint to run `/help`.

## Troubleshooting

- **Bot doesn't answer.** Check the room has the ReQurv bot added, and that the
  message actually addresses it (reply, `/command`, or `@ReQurv`). Messages that
  don't address the bot are intentionally ignored.
- **Registered but no replies, or "failed to post reply".** The reply path
  needs the room's token and a reachable backend URL, both captured at
  invocation time. A transport failure (e.g. a dead room, an oversized message)
  is logged as `RequrvHive Talk: failed to post reply in room` and swallowed —
  the job never fails the queue over a dead room.
- **No reply and a warning in the log.** `no permitted provider` means the
  user who triggered the turn (or the pinned owner) has no provider they may
  use. The binding stays put, so the turn retries once access is granted.
- **Replies cut off.** Long answers are truncated to Talk's 32,000-character
  message limit and end with an ellipsis, so an oversized reply is never lost
  to a failed post.
- **Re-register after a Talk upgrade.** Run `occ requrvhive:talk:register` if
  the bot disappears from a room's bot list after upgrading Talk.
