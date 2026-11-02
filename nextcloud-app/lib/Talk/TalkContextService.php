<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Talk;

use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\TalkBinding;
use OCA\RequrvHive\Db\TalkBindingMapper;
use OCA\RequrvHive\Service\ContextChatService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * The Talk room ↔ Hive conversation binding, the per-room shared context.
 *
 * Every Talk room the bot answers in maps to exactly one Hive conversation,
 * created by the first user to mention the bot. All later turns — from any
 * member of the room — run on that conversation and with its owner's provider
 * access and file permissions. The bot invocation layer turns room events
 * into calls here and back; this class owns no HTTP and no Talk protocol.
 */
class TalkContextService {
    /** How long a dead job's ambient claim survives before the room unlocks. */
    public const AMBIENT_LOCK_TTL = 900;
    /** Max ambient catch-up turns one job runs after its own turn. */
    public const AMBIENT_CATCH_UP_LIMIT = 2;
    /** Size of the rolling recent-messages window kept per room. */
    public const AMBIENT_CONTEXT_WINDOW = 5;
    /** Max characters of one room message kept in the window. */
    public const AMBIENT_CONTEXT_ENTRY_LENGTH = 2000;

    public function __construct(
        private TalkBindingMapper $bindingMapper,
        private ConversationMapper $conversationMapper,
        private MessageMapper $messageMapper,
        private MessageFileMapper $messageFileMapper,
        private LLMProviderFactory $providerFactory,
        private ContextChatService $contextChat,
        private IUserManager $userManager,
    ) {
    }

    /** The binding for a room's bot token, or null when the room is unbound. */
    public function find(string $talkToken): ?TalkBinding {
        return $this->bindingMapper->findByTalkToken($talkToken);
    }

    /**
     * Toggle the room's ambient mode — whether the bot answers messages that
     * do not address it. No-op when the room is unbound.
     */
    public function setAutoReply(string $talkToken, bool $on): void {
        $this->bindingMapper->setAutoReply($talkToken, $on);
    }

    /**
     * Claim the room's ambient lane for up to $ttl seconds. True when the
     * room was free and the caller now owns its ambient turns, until it
     * releases them or the claim self-expires after a dead job.
     */
    public function claimAmbientTurn(string $talkToken, ?int $ttl = null): bool {
        $ttl ??= self::AMBIENT_LOCK_TTL;
        $now = time();
        return $this->bindingMapper->claimAmbientTurn($talkToken, $now + $ttl, $now);
    }

    /** Drop the room's ambient lane claim. No-op when the room is unbound. */
    public function releaseAmbientTurn(string $talkToken): void {
        $this->bindingMapper->releaseAmbientTurn($talkToken);
    }

    /**
     * Remember the newest ambient message that arrived while the room was
     * busy, so the active job can run it as a catch-up. $payload is the full
     * job argument captured at invocation time.
     */
    public function setAutoPending(string $talkToken, string $payload): void {
        $this->bindingMapper->setAutoPending($talkToken, $payload);
    }

    /**
     * Fetch and clear the ambient message held while the room was busy, or
     * null when there is none. The payload is ready to run as a catch-up
     * turn: it is the job argument the listener captured for the message.
     *
     * @return array<string, mixed>|null
     */
    public function consumeAutoPending(string $talkToken): ?array {
        return $this->bindingMapper->consumeAutoPending($talkToken);
    }

    /**
     * Append one room message to the room's rolling recent-messages window,
     * keeping only the last AMBIENT_CONTEXT_WINDOW entries. No-op when the
     * room is unbound.
     */
    public function appendRecentMessage(string $talkToken, string $name, string $text): void {
        $this->bindingMapper->appendRecentMessage(
            $talkToken,
            $name,
            $text,
            self::AMBIENT_CONTEXT_WINDOW,
            self::AMBIENT_CONTEXT_ENTRY_LENGTH,
        );
    }

    /**
     * The Hive conversation a room shares, creating it on first use.
     *
     * The caller is the user whose message triggered the bot; on a first use
     * they become the conversation owner and its provider is pinned to their
     * currently permitted provider, so the room keeps answering from a stable
     * provider even if the owner later changes their defaults.
     *
     * @throws NoPermittedProviderException when creating and the first user has no permitted provider
     */
    public function getOrCreate(string $talkToken, string $userId, ?string $roomTitle = null): Conversation {
        $existing = $this->bindingMapper->findByTalkToken($talkToken);
        if ($existing !== null) {
            // The room's turns run as the owner's account. If that account was
            // deleted, nothing can run under it: drop the stale conversation and
            // rebind the room, making the current caller the new owner.
            if (!$this->userManager->userExists($existing->getOwnerUserId())) {
                $this->deleteConversationCascade($existing->getHiveConversationId());
                $this->bindingMapper->delete($existing);
            } else {
                try {
                    return $this->conversationMapper->findById($existing->getHiveConversationId());
                } catch (DoesNotExistException $e) {
                    // The bound conversation went away outside the reset flow;
                    // drop the stale binding and rebind the room below.
                    $this->bindingMapper->delete($existing);
                }
            }
        }

        $provider = $this->providerFactory->getProvider($userId);

        $now = time();
        $conversation = new Conversation();
        $conversation->setUserId($userId);
        $conversation->setTitle($this->defaultTitle($roomTitle));
        $conversation->setProvider($provider->getId());
        $conversation->setModel($provider->getModel($userId));
        $conversation->setCreatedAt($now);
        $conversation->setUpdatedAt($now);
        $conversation = $this->conversationMapper->insert($conversation);

        $binding = new TalkBinding();
        $binding->setTalkToken($talkToken);
        $binding->setHiveConversationId($conversation->getId());
        $binding->setOwnerUserId($userId);
        $binding->setCreatedAt($now);
        $binding->setUpdatedAt($now);
        $this->bindingMapper->insert($binding);

        return $conversation;
    }

    /**
     * The bound room's Hive conversation, or null when the room is unbound or
     * its conversation no longer exists. Read-only: never creates.
     */
    public function findConversation(string $talkToken): ?Conversation {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return null;
        }
        try {
            return $this->conversationMapper->findById($binding->getHiveConversationId());
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Repoint a bound room's shared conversation at a different provider,
     * adopting that provider's default model for the room owner. No-op when the
     * room is unbound. The caller is responsible for validating the provider id
     * against the owner's permissions.
     */
    public function setProvider(string $talkToken, string $providerId): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $ownerUserId = $binding->getOwnerUserId();
        $conversation = $this->conversationMapper->findById($binding->getHiveConversationId());
        $conversation->setProvider($providerId);
        $conversation->setModel($this->providerFactory->getProviderById($providerId)->getModel($ownerUserId));
        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);
    }

    /**
     * Override a bound room's shared conversation thinking mode. `false`
     * makes the provider skip the model's reasoning pass (faster answers);
     * `null` clears the override so the provider default applies. No-op when
     * the room is unbound. The caller is responsible for the owner check.
     */
    public function setThinking(string $talkToken, ?bool $thinking): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $conversation = $this->conversationMapper->findById($binding->getHiveConversationId());
        $conversation->setThinking($thinking);
        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);
    }

    /**
     * Detach a room: cascade-delete the bound Hive conversation (messages,
     * attached files, Context Chat index) and the binding itself. No-op when
     * the room is unbound.
     */
    public function reset(string $talkToken): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $this->deleteConversationCascade($binding->getHiveConversationId());
        $this->bindingMapper->delete($binding);
    }

    /**
     * Delete a Hive conversation and everything it owns: attached message
     * files, its messages, the conversation row, and its Context Chat index.
     * No-op when the conversation is already gone.
     */
    private function deleteConversationCascade(int $conversationId): void {
        try {
            $conversation = $this->conversationMapper->findById($conversationId);
            foreach ($this->messageMapper->findByConversation($conversationId) as $message) {
                $this->messageFileMapper->deleteByMessage($message->getId());
            }
            $this->messageMapper->deleteByConversation($conversationId);
            $this->conversationMapper->delete($conversation);
            $this->contextChat->removeConversation($conversationId);
        } catch (DoesNotExistException $e) {
            // The conversation is already gone; nothing left to cascade.
        }
    }

    private function defaultTitle(?string $roomTitle): string {
        $title = trim((string)$roomTitle);
        return $title === '' ? 'Talk conversation' : 'Talk: ' . $title;
    }
}
