<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Talk;

use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
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
 * messages, a "/command", or an explicit "@ReQurv" mention — and even then we
 * only enqueue a job; the turn itself runs off the request path because an
 * agentic LLM call must not block the sender.
 *
 * The event class only exists while Talk is installed, so it is referenced
 * by name and the listener is a no-op otherwise.
 *
 * @template-implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    public function __construct(
        private readonly IJobList $jobList,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
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

        $trigger = $this->detectTrigger($prompt, $object);
        if ($trigger === null) {
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
        if (preg_match('/@' . preg_quote(TalkBotRegistration::BOT_NAME, '/') . '\b/i', $prompt) === 1) {
            return 'mention';
        }
        return null;
    }
}
