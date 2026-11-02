<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ambient mode for the Talk bot: the room keeps being answered without the
 * bot being mentioned.
 *
 * auto_reply is the per-room opt-in (owner-only, toggled with /auto). While
 * ambient mode is on, the room owns one ambient turn at a time: auto_busy_until
 * is the lock the active job claims (a dead job's claim self-expires after its
 * TTL), and a message that arrives while the room is busy is coalesced into
 * auto_pending — the JSON job argument of the newest message, which the active
 * job runs as a catch-up. recent_messages is the rolling window of the room's
 * last few messages, so an ambient turn sees the room's flow, not only the
 * single message that fired it.
 */
class Version0016Date20261102000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('requrvhive_talk_bindings')) {
            return null;
        }

        $table = $schema->getTable('requrvhive_talk_bindings');

        if ($table->hasColumn('auto_reply')) {
            return null;
        }

        $table->addColumn('auto_reply', Types::BOOLEAN, [
            'notnull' => true,
            'default' => false,
        ]);
        $table->addColumn('auto_busy_until', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('auto_pending', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('recent_messages', Types::TEXT, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
