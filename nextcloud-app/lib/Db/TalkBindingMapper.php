<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TalkBinding>
 */
class TalkBindingMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'requrvhive_talk_bindings', TalkBinding::class);
    }

    /**
     * The binding for a Talk room's bot token, or null when the room is not
     * bound to a Hive conversation yet.
     */
    public function findByTalkToken(string $talkToken): ?TalkBinding {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * The binding pointing at a Hive conversation, or null when the
     * conversation is not bound to any Talk room.
     */
    public function findByHiveConversationId(int $hiveConversationId): ?TalkBinding {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hive_conversation_id', $qb->createNamedParameter($hiveConversationId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Toggle the room's ambient mode. Column-scoped so a concurrent ambient
     * lock or pending write on the same row is never clobbered.
     */
    public function setAutoReply(string $talkToken, bool $on): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('auto_reply', $qb->createNamedParameter($on ? 1 : 0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Claim the room's ambient lane: atomically set auto_busy_until to $until
     * only when the room is free (the lock is 0 or already expired). True
     * when the caller won the claim and owns the lane until it releases it —
     * or the claim self-expires after a dead job.
     */
    public function claimAmbientTurn(string $talkToken, int $until, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('auto_busy_until', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('auto_busy_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
                $qb->expr()->lte('auto_busy_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
            ));
        return $qb->executeStatement() > 0;
    }

    /** Drop the room's ambient lane claim so the next message can claim it. */
    public function releaseAmbientTurn(string $talkToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('auto_busy_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Remember the newest ambient message that arrived while the room was
     * busy, as a catch-up for the active job. Overwrites any earlier pending
     * payload: the room only ever catches up to its latest state.
     */
    public function setAutoPending(string $talkToken, string $payload): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('auto_pending', $qb->createNamedParameter($payload, IQueryBuilder::PARAM_STR))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Fetch and clear the pending ambient payload, or null when there is none
     * (unbound room, no pending, or an unparseable payload — which is still
     * cleared so it cannot clog the lane). Best-effort: the lane is owned by
     * a single job at a time, so a read-then-clear here cannot lose a turn.
     *
     * @return array<string, mixed>|null
     */
    public function consumeAutoPending(string $talkToken): ?array {
        $binding = $this->findByTalkToken($talkToken);
        if ($binding === null || (string)$binding->getAutoPending() === '') {
            return null;
        }

        $pending = json_decode((string)$binding->getAutoPending(), true);
        $this->clearAutoPending($talkToken);

        return is_array($pending) ? $pending : null;
    }

    private function clearAutoPending(string $talkToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('auto_pending', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Append one room message to the rolling recent-messages window, keeping
     * only the newest $max entries with each text capped at $maxTextLength.
     * No-op when the room is unbound. Best-effort context: two concurrent
     * appends may drop one entry, which only ever narrows the window.
     */
    public function appendRecentMessage(string $talkToken, string $name, string $text, int $max, int $maxTextLength): void {
        $binding = $this->findByTalkToken($talkToken);
        if ($binding === null) {
            return;
        }

        $window = json_decode((string)$binding->getRecentMessages(), true);
        if (!is_array($window)) {
            $window = [];
        }
        $window[] = [
            'name' => $name,
            'text' => mb_substr($text, 0, $maxTextLength),
        ];
        if (count($window) > $max) {
            $window = array_slice($window, -$max);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('recent_messages', $qb->createNamedParameter(json_encode($window, JSON_THROW_ON_ERROR), IQueryBuilder::PARAM_STR))
            ->where($qb->expr()->eq('talk_token', $qb->createNamedParameter($talkToken, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
