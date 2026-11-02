# ReQurv Talk bot

ReQurv can answer inside **Nextcloud Talk** rooms. When the bot is present in a
room, anyone who addresses it — by replying to one of its messages, sending a
`/command`, or mentioning it as `@ReQurv` (or the `@ai` alias) — gets an
answer from the ReQurv Hive model. Each room keeps its own persistent conversation, so context carries over
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
   ReQurv — or, in an ambient room, whether the room may still take the message
   up — and, if so, enqueues a `TalkReplyJob`. It also feeds every room message
   into the room's rolling recent-messages window (ambient mode). It never runs
   the model — an agentic LLM call must not block the sender's own request.
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
| `mention` | The message contains `@ReQurv` — or the alias `@ai` (both are accepted; the list lives in `TalkBotRegistration::MENTION_NAMES`) |

The mention autocompletion in Talk only offers the registered display name
(`ReQurv`), so type `@ai` as plain text. Either way the addressing token is
stripped before the question reaches the model.

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

Send these to the bot in the room. `/help`, `/model` (no argument),
`/thinking` (no argument), `/auto` (no argument), and chat messages work for
everyone in the room; `/new`, `/model <provider>`, `/thinking <on|off>`, and
`/auto <on|off>` are **owner-only**.

| Command | Who | Effect |
|---|---|---|
| `/help` | anyone | Show the command list |
| `/new` | owner | Drop the room's conversation so the next message starts fresh |
| `/model` | anyone | Show the room's current provider and model |
| `/model <provider>` | owner | Switch the room's provider, adopting that provider's default model |
| `/thinking` | anyone | Show the room's thinking state (provider default / on / off) |
| `/thinking <on\|off>` | owner | Enable or disable the model's reasoning pass for the room |
| `/auto` | anyone | Show the room's ambient mode |
| `/auto <on\|off>` | owner | Keep the room being answered without an @-mention (see [Ambient mode](#ambient-mode)) |

Disabling thinking makes the provider send `enable_thinking: false` to the
model instead of `reasoning_effort`, so the room answers without the
reasoning pass — much faster, with less deliberation on hard questions. The
override is stored on the room's conversation, so it survives across turns.

`/model <provider>` checks that the id is a known provider and that the owner
may use it; otherwise it replies with the available providers. An unknown
command replies with a hint to run `/help`.

## Ambient mode

By default the room only answers when it is addressed. **Ambient mode**
(`/auto on`, room owner) makes the bot take part in the conversation: any
message in the room can start a turn, and **the model decides per message
whether to answer or stay silent** — it replies with the `NO_REPLY` sentinel,
which posts nothing into the room while the message still stays in the shared
conversation as room flow.

How a turn runs in an ambient room:

- The triggering message is **framed with the room's rolling recent-messages
  window** (the last 5 messages, including the bot's own replies), so the model
  answers the room's flow, not an isolated line.
- **One ambient turn per room at a time** (the room's *ambient lane*). A
  message that arrives while a turn is running is held as the room's pending
  catch-up — the newest one wins; when the active turn finishes, the job runs
  the pending message as a catch-up before releasing the lane. Catch-ups are
  bounded (two per job) so a long burst cannot hold one job open forever.
- Messages from **guests** feed the window but never start a turn (there is no
  account to run the model under); very short messages (fewer than 3
  characters) do not start one either.
- Addressed messages — a reply to the bot, a `/command`, a mention — still run
  as plain turns and do not use the ambient lane.

The state lives on the room's binding, so it survives across turns. Note the
cost: in an ambient room, **every room message costs a model call** — most of
them end in silence, but they are still calls. Turn the mode off (`/auto off`)
in rooms that do not need it.

## Troubleshooting

- **Bot doesn't answer.** Check the room has the ReQurv bot added, and that the
  message actually addresses it (reply, `/command`, or a mention as `@ReQurv`
  / `@ai`). Messages that don't address the bot are intentionally ignored.
- **Registered but no replies, or "failed to post reply".** The reply path
  needs the room's token and a reachable backend URL, both captured at
  invocation time. A transport failure (e.g. a dead room, an oversized message)
  is logged as `RequrvHive Talk: failed to post reply in room` and swallowed —
  the job never fails the queue over a dead room.
- **No reply and a warning in the log.** `no permitted provider` means the
  user who triggered the turn (or the pinned owner) has no provider they may
  use. The binding stays put, so the turn retries once access is granted.
- **Ambient room is quiet.** With `/auto on`, silence is the normal outcome:
  the model answers `NO_REPLY` whenever it has nothing to add (a debug line
  `ambient turn stayed silent (NO_REPLY)` marks it in the log). Verify the
  mode is on with `/auto`; messages under 3 characters never trigger a turn.
- **Replies cut off.** Long answers are truncated to Talk's 32,000-character
  message limit and end with an ellipsis, so an oversized reply is never lost
  to a failed post.
- **Slow replies.** Reasoning models think before answering; on a reasoning
  model the room owner can speed the room up with `/thinking off` (and back
  with `/thinking on`). Talk itself shows no "bot is typing" indicator — the
  bot API has no typing endpoint — so the silence between the question and
  the answer is expected.
- **Re-register after a Talk upgrade.** Run `occ requrvhive:talk:register` if
  the bot disappears from a room's bot list after upgrading Talk.
- **Every reply fails with 401** (`X-Nextcloud-Talk-Bot-Signature` rejected).
  The stored bot secret and Talk's bot row have diverged — typically Talk
  dropped or rewrote the bot row without a version bump, so the automatic
  re-registration never fires. Re-registering alone cannot heal it, because
  both sides keep agreeing on the *old* secret. Rotate it and re-register in
  one step:

  ```bash
  occ requrvhive:talk:register --rotate-secret
  ```

  A single 401 is retried once automatically after a forced re-registration;
  a second 401 means rotation is needed. `occ requrvhive:doctor` reports the
  registration state.
