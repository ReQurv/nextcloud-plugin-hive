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
 * Three kinds of turn land here:
 *   - a chat message (reply / mention under any of the bot's names):
 *     resolves (creating on first use) the room's shared Hive conversation,
 *     runs the turn with the room owner's provider pin and file permissions,
 *     and posts the answer back
 *     through the Talk bot API.
 *   - an ambient message (the room has /auto on and nobody addressed the bot):
 *     the same turn framed with the room's recent-messages window, under a
 *     system note that lets the model stay silent (NO_REPLY). The turn runs
 *     under the room's ambient lane — one ambient turn per room at a time —
 *     and finishes by running any message held as pending, as a catch-up.
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
        . "/thinking <on|off> — enable/disable the model's reasoning (room owner)\n"
        . "/auto — show the room's ambient mode\n"
        . "/auto <on|off> — keep answering without being @-mentioned (room owner)";

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
     * Runs a chat message (reply / mention / ambient) through the shared room
     * conversation and posts the answer back into the room. Ambient turns
     * additionally release the room's ambient lane afterwards and run any
     * message held as pending while the room was busy, as a catch-up.
     */
    private function handleChatTurn(array $argument): void {
        if ((string)($argument['trigger'] ?? '') === 'ambient') {
            try {
                $this->runChatTurn($argument);
            } finally {
                $this->finishAmbientTurn((string)$argument['talkToken']);
            }
            return;
        }
        $this->runChatTurn($argument);
    }

    /**
     * One turn on the room's shared conversation. Ambient turns frame the
     * prompt with the room's recent-messages window and may end in silence
     * (the model's NO_REPLY), which simply does not post.
     */
    private function runChatTurn(array $argument): void {
        $talkToken = (string)$argument['talkToken'];
        $actorUserId = (string)$argument['actorUserId'];
        $prompt = (string)($argument['prompt'] ?? '');
        $ambient = (string)($argument['trigger'] ?? '') === 'ambient';

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

        // A mention trigger still carries the addressing token ("@ReQurv",
        // "@ai", ...) in the text; strip it so the model sees the question,
        // not the call.
        $cleanPrompt = $this->cleanPrompt($prompt);
        if ($cleanPrompt === '') {
            $this->logger->debug('RequrvHive Talk: message was only a bot mention, nothing to answer', [
                'talkToken' => $talkToken,
            ]);
            return;
        }

        // An ambient turn answers the room's flow, not an isolated line: frame
        // the prompt with the room's rolling recent-messages window.
        $turnPrompt = $cleanPrompt;
        if ($ambient) {
            $turnPrompt = $this->buildAmbientPrompt(
                $talkToken,
                $cleanPrompt,
                (string)($argument['actorName'] ?? ''),
            );
        }

        try {
            $result = $this->chatService->send($conversation, $turnPrompt, [], $ownerId, $ambient);
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

        if ($result['assistantMessage'] === null) {
            // Ambient silence: the model answered NO_REPLY, so it chose not
            // to join the room. Nothing to post; the room's flow stayed in
            // the shared context.
            $this->logger->debug('RequrvHive Talk: ambient turn stayed silent (NO_REPLY)', [
                'talkToken' => $talkToken,
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
            '/auto' => $this->handleAuto($talkToken, $actorUserId, $arg),
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
     * /auto — show the room's ambient mode, or (owner only) toggle it.
     * Ambient mode lets the room keep being answered without a mention: the
     * bot takes part in the conversation and the model decides per message
     * whether to answer or stay silent.
     */
    private function handleAuto(string $talkToken, string $actorUserId, string $arg): string {
        $binding = $this->context->find($talkToken);
        if ($binding === null) {
            return 'No conversation in this room yet — send a message to start one.';
        }

        $arg = strtolower($arg);
        if ($arg === '') {
            return $binding->getAutoReply()
                ? 'Ambient mode: on — the room keeps being answered without a mention.'
                : 'Ambient mode: off — the room only answers when addressed.';
        }
        if (!in_array($arg, ['on', 'off'], true)) {
            return 'Usage: /auto on|off';
        }
        if ($binding->getOwnerUserId() !== $actorUserId) {
            return 'Only the room owner can change ambient mode.';
        }
        $this->context->setAutoReply($talkToken, $arg === 'on');
        return $arg === 'on'
            ? 'Ambient mode enabled — the room keeps being answered without a mention.'
            : 'Ambient mode disabled — the room only answers when addressed.';
    }

    /**
     * Release the room's ambient lane and, when a message arrived while the
     * room was busy, run it as a catch-up turn. Bounded so a long burst
     * cannot hold one job open forever: whatever is still held after the
     * limit is picked up by the next ambient message.
     */
    private function finishAmbientTurn(string $talkToken, int $depth = 0): void {
        $this->context->releaseAmbientTurn($talkToken);

        if ($depth >= TalkContextService::AMBIENT_CATCH_UP_LIMIT) {
            return;
        }
        $pending = $this->context->consumeAutoPending($talkToken);
        if ($pending === null) {
            return;
        }

        // Reclaim the lane for the catch-up turn. When another worker won the
        // race, the room is theirs again — hand the pending message over to
        // their lane instead of dropping it.
        if (!$this->context->claimAmbientTurn($talkToken)) {
            $this->context->setAutoPending($talkToken, json_encode($pending, JSON_THROW_ON_ERROR));
            return;
        }
        try {
            $this->runChatTurn($pending);
        } finally {
            $this->finishAmbientTurn($talkToken, $depth + 1);
        }
    }

    /**
     * Frame an ambient turn: attribute the triggering message to its speaker
     * and precede it with the room's rolling recent-messages window, so the
     * model answers the room's flow, not an isolated line. With an empty
     * window the attributed line alone is the prompt.
     */
    private function buildAmbientPrompt(string $talkToken, string $prompt, string $actorName): string {
        $binding = $this->context->find($talkToken);
        $window = $binding !== null ? $this->decodeWindow((string)$binding->getRecentMessages()) : [];

        $speaker = $actorName !== '' ? $actorName : 'Someone';
        if ($window === []) {
            return $speaker . ': ' . $prompt;
        }

        $lines = array_map(
            static fn (array $entry): string => $entry['name'] . ': ' . $entry['text'],
            $window,
        );
        return 'Recent messages in this room:' . "\n"
            . implode("\n", $lines) . "\n"
            . 'Current message from ' . $speaker . ': ' . $prompt;
    }

    /**
     * The room's rolling recent-messages window, decoded and trimmed to well-
     * formed entries. Returns [] when absent or unparseable.
     *
     * @return list<array{name: string, text: string}>
     */
    private function decodeWindow(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $window = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['name'], $entry['text'])) {
                $window[] = [
                    'name' => (string)$entry['name'],
                    'text' => (string)$entry['text'],
                ];
            }
        }
        return $window;
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
     * Remove the bot's own mention token (under any of its names, e.g.
     * "@ReQurv" or "@ai") and normalise the whitespace around it, so
     * "@ai what is X" becomes "what is X". Returns '' when nothing but the
     * mention was said.
     */
    private function cleanPrompt(string $prompt): string {
        $withoutMention = preg_replace(TalkBotRegistration::mentionPattern(), ' ', $prompt);
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
