<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\BackgroundJob;

use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
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
 * Hive conversation and, from Plan 4 on, runs the turn and posts the answer
 * back into the room through the Talk bot API.
 *
 * Plan 3 scope: resolve the binding and log. The LLM turn and the OCS reply
 * are added by the follow-up plan.
 */
class TalkReplyJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly TalkContextService $context,
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

        // Plan 4 runs the turn on $conversation and posts the reply into the
        // room via TalkBotClient.
        $this->logger->info('RequrvHive Talk: bot turn queued (reply pending Plan 4)', [
            'talkToken' => $talkToken,
            'conversationId' => $conversation->getId(),
            'trigger' => (string)($argument['trigger'] ?? ''),
            'prompt' => (string)($argument['prompt'] ?? ''),
        ]);
    }
}
