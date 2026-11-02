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
 * @method bool getAutoReply()
 * @method void setAutoReply(bool $autoReply)
 * @method int getAutoBusyUntil()
 * @method void setAutoBusyUntil(int $autoBusyUntil)
 * @method string|null getAutoPending()
 * @method void setAutoPending(?string $autoPending)
 * @method string|null getRecentMessages()
 * @method void setRecentMessages(?string $recentMessages)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class TalkBinding extends Entity {
    protected string $talkToken = '';
    protected int $hiveConversationId = 0;
    protected string $ownerUserId = '';
    /** Ambient mode: the room keeps being answered without a mention. */
    protected bool $autoReply = false;
    /** Ambient lock: claim valid while in the future, 0 = free. */
    protected int $autoBusyUntil = 0;
    /** JSON job argument of the newest ambient message held while the room is busy. */
    protected ?string $autoPending = null;
    /** JSON rolling window of the room's last few messages, for ambient context. */
    protected ?string $recentMessages = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('talkToken', 'string');
        $this->addType('hiveConversationId', 'integer');
        $this->addType('ownerUserId', 'string');
        $this->addType('autoReply', 'boolean');
        $this->addType('autoBusyUntil', 'integer');
        $this->addType('autoPending', 'string');
        $this->addType('recentMessages', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
