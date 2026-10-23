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
 * Bind a Nextcloud Talk room to a ReQurv Hive conversation.
 *
 * Each Talk room that the bot answers in maps to exactly one Hive
 * conversation, shared by everyone in the room. The talk_token is Talk's
 * per-room bot token and is the stable key the bot events arrive with, so it
 * is unique. owner_user_id records the user whose first mention created the
 * conversation: all bot turns run with that user's provider access and file
 * permissions, so it is the account the room is "on the hook" for.
 *
 * last_bot_reference_id holds the Talk message id of the conversation turn
 * the bot last replied to, so a follow-up reply can thread onto the same
 * exchange instead of starting a new one.
 */
class Version0015Date20261023000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('requrvhive_talk_bindings')) {
            return null;
        }

        $table = $schema->createTable('requrvhive_talk_bindings');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('talk_token', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('hive_conversation_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('owner_user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('last_bot_reference_id', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['talk_token'], 'requrvhive_talkb_token');
        $table->addIndex(['hive_conversation_id'], 'requrvhive_talkb_conv');

        return $schema;
    }
}
