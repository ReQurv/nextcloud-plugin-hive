<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTalkToken()
 * @method void setTalkToken(string $talkToken)
 * @method int getHiveConversationId()
 * @method void setHiveConversationId(int $hiveConversationId)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string|null getLastBotReferenceId()
 * @method void setLastBotReferenceId(?string $lastBotReferenceId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class TalkBinding extends Entity {
    protected string $talkToken = '';
    protected int $hiveConversationId = 0;
    protected string $ownerUserId = '';
    /** Talk message id of the turn the bot last replied to; null until the first reply. */
    protected ?string $lastBotReferenceId = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('talkToken', 'string');
        $this->addType('hiveConversationId', 'integer');
        $this->addType('ownerUserId', 'string');
        $this->addType('lastBotReferenceId', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
