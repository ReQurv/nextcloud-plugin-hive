<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Talk;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Db\TalkBinding;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Listens for Nextcloud Talk bot invocations and turns the ones that address
 * ReQurv into queued chat turns.
 *
 * Talk dispatches a BotInvokeEvent for every message in a room the bot joins.
 * We only act when the message is directed at us — a reply to one of our
 * messages, a "/command", or an explicit mention under any of the bot's
 * names (@ReQurv, @ai — see TalkBotRegistration::MENTION_NAMES) — and even
 * then we only enqueue a job; the turn itself runs off the request path
 * because an agentic LLM call must not block the sender.
 *
 * Rooms with ambient mode on (/auto on) additionally act on messages that do
 * not address the bot: the model decides per message whether to answer or
 * stay silent, and a per-room lock keeps a single ambient turn in flight —
 * a message that arrives while the room is busy is held as pending for the
 * active job to run as a catch-up.
 *
 * The event class only exists while Talk is installed, so it is referenced
 * by name and the listener is a no-op otherwise.
 *
 * @template-implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    /**
     * Ambient turns are not worth a model call under this many characters —
     * a lone "ok" or emoji the group chat does not need an answer to.
     */
    private const MIN_AMBIENT_MESSAGE_LENGTH = 3;

    public function __construct(
        private readonly IJobList $jobList,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly TalkContextService $context,
    ) {
    }

    public function handle(Event $event): void {
        // OCA\Talk\Events\BotInvokeEvent only exists with Talk installed.
        if (!($event instanceof \OCA\Talk\Events\BotInvokeEvent)) {
            return;
        }

        try {
            $this->processInvocation($event->getBotUrl(), $event->getMessage(), $this->urlGenerator->getBaseUrl());
        } catch (\Throwable $e) {
            // A failure here must never break the sender's own request.
            $this->logger->error('RequrvHive Talk: failed to process bot invocation', ['exception' => $e]);
        }
    }

    /**
     * Core of the invocation handling, isolated from the Talk event type so it
     * can be exercised without Talk present.
     *
     * Decides whether the given ActivityStream message addresses ReQurv and,
     * if so, enqueues a TalkReplyJob carrying everything the turn needs.
     *
     * @param array<string, mixed> $message the ActivityStream body Talk sent
     */
    public function processInvocation(string $botUrl, array $message, string $backendUrl): void {
        if ($botUrl !== TalkBotRegistration::BOT_URL) {
            return;
        }
        if (($message['type'] ?? '') !== 'Create') {
            return;
        }

        $object = $message['object'] ?? [];
        if (($object['name'] ?? '') !== 'message') {
            // System messages and bot-management events are not chat.
            return;
        }

        $target = $message['target'] ?? [];
        $talkToken = (string)($target['id'] ?? '');
        if ($talkToken === '') {
            return;
        }

        // object.content is a JSON string: {"message": "...", "parameters": {...}}
        $content = json_decode((string)($object['content'] ?? ''), true);
        if (!is_array($content)) {
            return;
        }
        $prompt = trim((string)($content['message'] ?? ''));
        if ($prompt === '') {
            return;
        }

        // The room's ambient context: when the room has ambient mode on, every
        // message — including ones the bot will not act on — feeds the rolling
        // window, so an ambient turn sees the room's flow, not only the single
        // message that fired it.
        $binding = $this->context->find($talkToken);
        if ($binding !== null && $binding->getAutoReply()) {
            $this->context->appendRecentMessage(
                $talkToken,
                (string)($message['actor']['name'] ?? $message['actor']['id'] ?? ''),
                $prompt,
            );
        }

        $trigger = $this->detectTrigger($prompt, $object);
        if ($trigger === null) {
            // Not addressed to us: only an ambient room may still act on it.
            $this->maybeQueueAmbientTurn($binding, $message, $talkToken, $prompt, $backendUrl);
            return;
        }

        // Only real users can own the room's shared conversation; guests and
        // other actor types have no account to run the turn as.
        $actorId = (string)($message['actor']['id'] ?? '');
        if (!str_starts_with($actorId, 'users/')) {
            return;
        }
        $actorUserId = substr($actorId, strlen('users/'));

        $talkMessageId = (int)($object['id'] ?? 0);

        $this->jobList->add(TalkReplyJob::class, [
            'talkToken' => $talkToken,
            'roomName' => (string)($target['name'] ?? ''),
            'prompt' => $prompt,
            'actorUserId' => $actorUserId,
            'talkMessageId' => $talkMessageId,
            'backendUrl' => rtrim($backendUrl, '/'),
            'trigger' => $trigger,
        ]);
    }

    /**
     * Ambient mode: in a room with /auto on, a message from a real user that
     * does not address the bot can still start a turn — the model decides
     * whether to answer or stay silent. The per-room lock keeps a single
     * ambient turn in flight; a message that arrives while the room is busy
     * is held as the room's pending catch-up instead of queuing a parallel
     * job.
     *
     * @param array<string, mixed> $message the ActivityStream body Talk sent
     */
    private function maybeQueueAmbientTurn(?TalkBinding $binding, array $message, string $talkToken, string $prompt, string $backendUrl): void {
        if ($binding === null || !$binding->getAutoReply()) {
            return;
        }
        if (mb_strlen($prompt) < self::MIN_AMBIENT_MESSAGE_LENGTH) {
            return;
        }

        $actorId = (string)($message['actor']['id'] ?? '');
        if (!str_starts_with($actorId, 'users/')) {
            return;
        }
        $actorUserId = substr($actorId, strlen('users/'));

        $argument = [
            'talkToken' => $talkToken,
            'roomName' => (string)($message['target']['name'] ?? ''),
            'prompt' => $prompt,
            'actorUserId' => $actorUserId,
            'actorName' => (string)($message['actor']['name'] ?? $actorUserId),
            'talkMessageId' => (int)($message['object']['id'] ?? 0),
            'backendUrl' => rtrim($backendUrl, '/'),
            'trigger' => 'ambient',
        ];

        if ($this->context->claimAmbientTurn($talkToken)) {
            $this->jobList->add(TalkReplyJob::class, $argument);
            return;
        }

        // The room is busy: hold this message (overwriting any earlier one —
        // the room only ever catches up to its newest state) for the active
        // job to run as a catch-up.
        $this->context->setAutoPending($talkToken, json_encode($argument, JSON_THROW_ON_ERROR));
        $this->logger->debug('RequrvHive Talk: room busy, ambient message held as pending catch-up', [
            'talkToken' => $talkToken,
        ]);
    }

    /**
     * Which of the "this is for us" signals fired, or null to ignore.
     *
     * @param array<string, mixed> $object the ActivityStream Note
     */
    private function detectTrigger(string $prompt, array $object): ?string {
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (is_array($inReplyTo) && ($inReplyTo['actor']['id'] ?? '') === TalkBotRegistration::botActorRef()) {
            return 'reply';
        }
        if (str_starts_with($prompt, '/')) {
            return 'command';
        }
        if (preg_match(TalkBotRegistration::mentionPattern(), $prompt) === 1) {
            return 'mention';
        }
        return null;
    }
}
