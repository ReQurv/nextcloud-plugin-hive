<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\BackgroundJob;

use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Talk\TalkBotClient;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use OCA\RequrvHive\Talk\TalkContextService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Executes one Talk-triggered chat turn off the request path.
 *
 * Talk's bot event arrives inside the sender's own request, so the turn — an
 * agentic LLM call that can run for a long time — is enqueued instead of
 * executed there. The job resolves (creating on first use) the room's shared
 * Hive conversation, runs the turn with the room owner's provider pin and
 * file permissions, and posts the answer back into the room through the Talk
 * bot API.
 *
 * The room's conversation is shared: whoever triggered the turn, the model
 * call runs as the owner (the first user to bring the bot into the room), so
 * the whole room draws on one context and one set of file permissions.
 *
 * Command triggers ("/new", "/help", "/model") are recognised but not yet
 * acted on — that is the next plan — so they are skipped here rather than
 * fed to the model as chat.
 */
class TalkReplyJob extends QueuedJob {
    /**
     * Talk's default maximum message length. Long answers are truncated to it
     * so a single oversized reply cannot fail the whole post (a 413 would
     * drop the answer entirely); a little tail cut is the lesser loss.
     */
    private const TALK_MAX_MESSAGE_LENGTH = 32000;

    public function __construct(
        ITimeFactory $time,
        private readonly TalkContextService $context,
        private readonly ConversationChatService $chatService,
        private readonly TalkBotClient $talkClient,
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

        $trigger = (string)($argument['trigger'] ?? '');
        $prompt = (string)($argument['prompt'] ?? '');

        // Commands are their own plan; until then, never feed them to the model
        // as ordinary chat.
        if ($trigger === 'command') {
            $this->logger->debug('RequrvHive Talk: command trigger skipped (handled by a later plan)', [
                'talkToken' => $talkToken,
                'prompt' => $prompt,
            ]);
            return;
        }

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

        $backendUrl = (string)($argument['backendUrl'] ?? '');
        if ($backendUrl === '') {
            $this->logger->error('RequrvHive Talk: no backend URL captured; cannot reply in room', [
                'talkToken' => $talkToken,
            ]);
            return;
        }

        try {
            $this->talkClient->sendMessage(
                $talkToken,
                $this->fitToTalk($reply),
                $backendUrl,
                (int)($argument['talkMessageId'] ?? 0),
                (string)($argument['referenceId'] ?? ''),
            );
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive Talk: failed to post reply in room', [
                'talkToken' => $talkToken,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->context->recordBotReference($talkToken, (string)($argument['referenceId'] ?? ''));

        $this->logger->info('RequrvHive Talk: bot replied in room', [
            'talkToken' => $talkToken,
            'conversationId' => $conversation->getId(),
            'owner' => $ownerId,
            'trigger' => $trigger,
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
