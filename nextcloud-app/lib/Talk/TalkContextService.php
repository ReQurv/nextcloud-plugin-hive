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
    public function __construct(
        private TalkBindingMapper $bindingMapper,
        private ConversationMapper $conversationMapper,
        private MessageMapper $messageMapper,
        private MessageFileMapper $messageFileMapper,
        private LLMProviderFactory $providerFactory,
        private ContextChatService $contextChat,
    ) {
    }

    /** The binding for a room's bot token, or null when the room is unbound. */
    public function find(string $talkToken): ?TalkBinding {
        return $this->bindingMapper->findByTalkToken($talkToken);
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
            try {
                return $this->conversationMapper->findById($existing->getHiveConversationId());
            } catch (DoesNotExistException $e) {
                // The bound conversation went away outside the reset flow; drop
                // the stale binding and rebind the room below.
                $this->bindingMapper->delete($existing);
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
     * Detach a room: cascade-delete the bound Hive conversation (messages,
     * attached files, Context Chat index) and the binding itself. No-op when
     * the room is unbound.
     */
    public function reset(string $talkToken): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $conversationId = $binding->getHiveConversationId();

        try {
            $conversation = $this->conversationMapper->findById($conversationId);
            foreach ($this->messageMapper->findByConversation($conversationId) as $message) {
                $this->messageFileMapper->deleteByMessage($message->getId());
            }
            $this->messageMapper->deleteByConversation($conversationId);
            $this->conversationMapper->delete($conversation);
            $this->contextChat->removeConversation($conversationId);
        } catch (DoesNotExistException $e) {
            // The conversation is already gone; just drop the stale binding.
        }

        $this->bindingMapper->delete($binding);
    }

    /**
     * Remember which Talk message the bot's last reply threaded onto, so a
     * follow-up in the same exchange can reply to the same message.
     */
    public function recordBotReference(string $talkToken, string $referenceId): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $binding->setLastBotReferenceId($referenceId);
        $binding->setUpdatedAt(time());
        $this->bindingMapper->update($binding);
    }

    /** Forget the last reference so the next reply starts a fresh thread. */
    public function clearBotReference(string $talkToken): void {
        $binding = $this->bindingMapper->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }
        $binding->setLastBotReferenceId(null);
        $binding->setUpdatedAt(time());
        $this->bindingMapper->update($binding);
    }

    private function defaultTitle(?string $roomTitle): string {
        $title = trim((string)$roomTitle);
        return $title === '' ? 'Talk conversation' : 'Talk: ' . $title;
    }
}
