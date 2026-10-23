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
}
