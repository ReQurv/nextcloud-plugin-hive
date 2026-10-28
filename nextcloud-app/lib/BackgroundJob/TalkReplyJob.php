<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\BackgroundJob;

use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Talk\TalkBotClient;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Executes one Talk-triggered bot turn off the request path.
 *
 * Talk's bot event arrives inside the sender's own request, so the work — an
 * agentic LLM call that can run for a long time, or a command — is enqueued
 * instead of executed there.
 *
 * Two kinds of turn land here:
 *   - a chat message (reply / @ReQurv mention): resolves (creating on first
 *     use) the room's shared Hive conversation, runs the turn with the room
 *     owner's provider pin and file permissions, and posts the answer back
 *     through the Talk bot API.
 *   - a "/command": handled locally (no LLM) and answered directly in the room.
 *
 * The room's conversation is shared: whoever triggered the turn, the model
 * call runs as the owner (the first user to bring the bot into the room), so
 * the whole room draws on one context and one set of file permissions. The
 * mutating commands (/new, /model <provider>) are owner-only for the same
 * reason.
 */
class TalkReplyJob extends QueuedJob {
    /**
     * Talk's default maximum message length. Long answers are truncated to it
     * so a single oversized reply cannot fail the whole post (a 413 would
     * drop the answer entirely); a little tail cut is the lesser loss.
     */
    private const TALK_MAX_MESSAGE_LENGTH = 32000;

    private const HELP_TEXT =
        "ReQurv commands:\n"
        . "/help — show this help\n"
        . "/new — start a fresh conversation (room owner)\n"
        . "/model — show the current model\n"
        . "/model <provider> — switch the room's provider (room owner)\n"
        . "/thinking — show the room's thinking state\n"
        . "/thinking <on|off> — enable/disable the model's reasoning (room owner)";

    public function __construct(
        ITimeFactory $time,
        private readonly TalkContextService $context,
        private readonly ConversationChatService $chatService,
        private readonly TalkBotClient $talkClient,
        private readonly LLMProviderFactory $providerFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $talkToken = (string)($argument['talkToken'] ?? '');
        $actorUserId = (string)($argument['actorUserId'] ?? '');
        if ($talkToken === '' || $actorUserId === '') {
            return;
        }

        if ((string)($argument['trigger'] ?? '') === 'command') {
            $this->handleCommand($argument);
            return;
        }

        $this->handleChatTurn($argument);
    }

    /**
     * Runs a chat message (reply / mention) through the shared room
     * conversation and posts the answer back into the room.
     */
    private function handleChatTurn(array $argument): void {
        $talkToken = (string)$argument['talkToken'];
        $actorUserId = (string)$argument['actorUserId'];
        $prompt = (string)($argument['prompt'] ?? '');

        try {
            $conversation = $this->context->getOrCreate(
                $talkToken,
                $actorUserId,
                (string)($argument['roomName'] ?? ''),
            );
        } catch (NoPermittedProviderException $e) {
            // The triggering user has no provider they may use, so there is no
            // conversation to run the turn in. Nothing to do.
            $this->logger->warning('RequrvHive Talk: no permitted provider for bot trigger', [
                'talkToken' => $talkToken,
                'actorUserId' => $actorUserId,
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive Talk: failed to resolve room binding', [
                'talkToken' => $talkToken,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // The room shares one conversation owned by its first user: every turn
        // — regardless of who triggered it — runs with the owner's provider pin
        // and file permissions, so the whole room draws on one context.
        $ownerId = (string)$conversation->getUserId();

        // A mention trigger still carries the "@ReQurv" addressing token in the
        // text; strip it so the model sees the question, not the call.
        $cleanPrompt = $this->cleanPrompt($prompt);
        if ($cleanPrompt === '') {
            $this->logger->debug('RequrvHive Talk: message was only a bot mention, nothing to answer', [
                'talkToken' => $talkToken,
            ]);
            return;
        }

        try {
            $result = $this->chatService->send($conversation, $cleanPrompt, [], $ownerId);
        } catch (NoPermittedProviderException $e) {
            // The owner's pinned provider was blocked after the room bound; the
            // turn cannot run. The binding stays put so a future trigger retries.
            $this->logger->warning('RequrvHive Talk: room owner has no permitted provider', [
                'talkToken' => $talkToken,
                'owner' => $ownerId,
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive Talk: chat turn failed', [
                'talkToken' => $talkToken,
                'owner' => $ownerId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            return;
        }

        $reply = (string)$result['assistantMessage']->getContent();
        if ($result['modelError'] !== null || $reply === '') {
            // The model failed: send() already persisted the error into the
            // conversation, but echoing a raw provider error into the room would
            // leak internals, so we only log it here.
            $this->logger->warning('RequrvHive Talk: model returned an error; not replying in room', [
                'talkToken' => $talkToken,
                'owner' => $ownerId,
                'modelError' => $result['modelError'],
            ]);
            return;
        }

        $this->postToRoom(
            $talkToken,
            $reply,
            (string)($argument['backendUrl'] ?? ''),
            (int)($argument['talkMessageId'] ?? 0),
        );
    }

    /**
     * Handles a "/command" locally (no LLM) and posts the result into the room.
     */
    private function handleCommand(array $argument): void {
        $talkToken = (string)$argument['talkToken'];
        $actorUserId = (string)$argument['actorUserId'];

        [$command, $arg] = $this->parseCommand((string)($argument['prompt'] ?? ''));

        $reply = match ($command) {
            '/help' => self::HELP_TEXT,
            '/new' => $this->handleNew($talkToken, $actorUserId),
            '/model' => $this->handleModel($talkToken, $actorUserId, (string)($argument['roomName'] ?? ''), $arg),
            '/thinking' => $this->handleThinking($talkToken, $actorUserId, $arg),
            default => 'Unknown command ' . $command . ' — try /help',
        };

        $this->postToRoom(
            $talkToken,
            $reply,
            (string)($argument['backendUrl'] ?? ''),
            (int)($argument['talkMessageId'] ?? 0),
        );
    }

    /**
     * /new — drop the room's shared conversation so the next message starts
     * over. Owner only: it wipes the context the whole room draws on.
     */
    private function handleNew(string $talkToken, string $actorUserId): string {
        $binding = $this->context->find($talkToken);
        if ($binding === null) {
            return 'There is no conversation in this room yet — your next message will start one.';
        }
        if ($binding->getOwnerUserId() !== $actorUserId) {
            return 'Only the room owner can start a fresh conversation.';
        }
        $this->context->reset($talkToken);
        return 'Conversation reset. Your next message starts fresh.';
    }

    /**
     * /model — show the room's current model, or (owner only) switch the room's
     * provider and adopt that provider's default model.
     */
    private function handleModel(string $talkToken, string $actorUserId, string $roomName, string $arg): string {
        if ($arg === '') {
            $conversation = $this->context->findConversation($talkToken);
            if ($conversation === null) {
                return 'No conversation in this room yet — send a message to start one.';
            }
            $ownerId = (string)$conversation->getUserId();
            $providerId = $conversation->getProvider();
            $label = ($providerId !== null && $this->providerFactory->isKnownProviderId($providerId))
                ? $this->providerFactory->getProviderById($providerId)->getLabel()
                : $this->providerFactory->getProvider($ownerId)->getLabel();
            return 'Current model: ' . $label . ' — ' . $conversation->getModel();
        }

        try {
            $conversation = $this->context->getOrCreate($talkToken, $actorUserId, $roomName);
        } catch (NoPermittedProviderException $e) {
            return 'No AI provider is configured for this room yet.';
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive Talk: /model could not resolve the room conversation', [
                'talkToken' => $talkToken,
                'error' => $e->getMessage(),
            ]);
            return 'Something went wrong looking up the room conversation.';
        }

        $ownerId = (string)$conversation->getUserId();
        if ($ownerId !== $actorUserId) {
            return 'Only the room owner can change the model.';
        }
        if (!$this->providerFactory->isKnownProviderId($arg)) {
            return 'Unknown provider "' . $arg . '". Available: ' . $this->availableProviders($ownerId);
        }
        if (!$this->providerFactory->isAllowedForUser($arg, $ownerId)) {
            return 'The room owner is not permitted to use provider "' . $arg . '".';
        }

        $this->context->setProvider($talkToken, $arg);
        $provider = $this->providerFactory->getProviderById($arg);
        return 'Switched to ' . $provider->getLabel() . ' — ' . $provider->getModel($ownerId) . '.';
    }

    /**
     * /thinking — show the room's thinking state, or (owner only) override it.
     * Disabling thinking makes the provider skip the model's reasoning pass,
     * so room answers come back much faster; the trade-off is less
     * deliberation on hard questions.
     */
    private function handleThinking(string $talkToken, string $actorUserId, string $arg): string {
        $conversation = $this->context->findConversation($talkToken);
        if ($conversation === null) {
            return 'No conversation in this room yet — send a message to start one.';
        }

        $arg = strtolower($arg);
        if ($arg === '') {
            $thinking = $conversation->getThinking();
            $state = $thinking === null ? 'provider default' : ($thinking ? 'on' : 'off');
            return 'Thinking: ' . $state . '.';
        }
        if (!in_array($arg, ['on', 'off'], true)) {
            return 'Usage: /thinking on|off';
        }
        if ((string)$conversation->getUserId() !== $actorUserId) {
            return 'Only the room owner can change thinking.';
        }
        $this->context->setThinking($talkToken, $arg === 'on');
        return $arg === 'on'
            ? 'Thinking enabled — the model will reason before answering.'
            : 'Thinking disabled — the room will answer without the reasoning pass.';
    }

    /**
     * Splits "/model openai" into ['/model', 'openai']. Returns ['',''] for an
     * empty prompt.
     *
     * @return array{0: string, 1: string}
     */
    private function parseCommand(string $raw): array {
        $parts = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $command = strtolower((string)array_shift($parts));
        return [$command, implode(' ', $parts)];
    }

    /**
     * The provider ids the owner may use, as "Label (id)" pairs for a reply.
     */
    private function availableProviders(string $ownerId): string {
        $ids = $this->providerFactory->getProviderIdsForUser($ownerId);
        if ($ids === []) {
            return 'none configured';
        }
        return implode(', ', array_map(
            fn(string $id): string => $this->providerFactory->getProviderById($id)->getLabel() . ' (' . $id . ')',
            $ids,
        ));
    }

    /**
     * Posts a bot message into the room. Transport failures are logged, never
     * thrown — a job must not fail the queue on a dead room.
     */
    private function postToRoom(string $talkToken, string $text, string $backendUrl, int $talkMessageId): void {
        if ($backendUrl === '') {
            $this->logger->error('RequrvHive Talk: no backend URL captured; cannot reply in room', [
                'talkToken' => $talkToken,
            ]);
            return;
        }

        try {
            $this->talkClient->sendMessage(
                $talkToken,
                $this->fitToTalk($text),
                $backendUrl,
                $talkMessageId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive Talk: failed to post reply in room', [
                'talkToken' => $talkToken,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger->info('RequrvHive Talk: bot replied in room', [
            'talkToken' => $talkToken,
        ]);
    }

    /**
     * Remove the bot's own mention token and normalise the whitespace around
     * it, so "@ReQurv what is X" becomes "what is X". Returns '' when nothing
     * but the mention was said.
     */
    private function cleanPrompt(string $prompt): string {
        $withoutMention = preg_replace(
            '/@' . preg_quote(TalkBotRegistration::BOT_NAME, '/') . '\b/i',
            ' ',
            $prompt,
        );
        if ($withoutMention === null) {
            return trim($prompt);
        }
        $normalized = preg_replace('/\s{2,}/', ' ', $withoutMention) ?? $withoutMention;
        return trim($normalized);
    }

    /**
     * Cut an answer to Talk's message limit, ending with an ellipsis, so it
     * can always be posted.
     */
    private function fitToTalk(string $reply): string {
        if (mb_strlen($reply) <= self::TALK_MAX_MESSAGE_LENGTH) {
            return $reply;
        }
        return mb_substr($reply, 0, self::TALK_MAX_MESSAGE_LENGTH - 1) . '…';
    }
}
