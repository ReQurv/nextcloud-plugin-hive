<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20260218000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        $this->createConversationsTable($schema);
        $this->createMessagesTable($schema);
        $this->createMessageFilesTable($schema);
        $this->createUsageStatsTable($schema);
        $this->createPromptsTable($schema);
        $this->createCoworkersTable($schema);

        return $schema;
    }

    private function createConversationsTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_conversations')) {
            return;
        }

        $table = $schema->createTable('requrvhive_conversations');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('title', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('model', Types::STRING, [
            'notnull' => true,
            'length' => 100,
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
        $table->addIndex(['user_id'], 'requrvhive_conv_user_idx');
    }

    private function createMessagesTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_messages')) {
            return;
        }

        $table = $schema->createTable('requrvhive_messages');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('conversation_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('role', Types::STRING, [
            'notnull' => true,
            'length' => 16,
        ]);
        $table->addColumn('content', Types::TEXT, [
            'notnull' => true,
        ]);
        $table->addColumn('input_tokens', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('output_tokens', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['conversation_id'], 'requrvhive_msg_conv_idx');
    }

    private function createMessageFilesTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_message_files')) {
            return;
        }

        $table = $schema->createTable('requrvhive_message_files');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('message_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('file_path', Types::STRING, [
            'notnull' => true,
            'length' => 4000,
        ]);
        $table->addColumn('file_name', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('mime_type', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['message_id'], 'requrvhive_msgfile_msg_idx');
    }

    private function createUsageStatsTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_usage_stats')) {
            return;
        }

        $table = $schema->createTable('requrvhive_usage_stats');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('model', Types::STRING, [
            'notnull' => true,
            'length' => 100,
        ]);
        $table->addColumn('input_tokens', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('output_tokens', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('request_type', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('conversation_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'requrvhive_usage_user_idx');
        $table->addIndex(['created_at'], 'requrvhive_usage_time_idx');
    }

    private function createPromptsTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_prompts')) {
            return;
        }

        $table = $schema->createTable('requrvhive_prompts');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('title', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('description', Types::STRING, [
            'notnull' => false,
            'length' => 1000,
        ]);
        $table->addColumn('content', Types::TEXT, [
            'notnull' => true,
        ]);
        $table->addColumn('is_active', Types::SMALLINT, [
            'notnull' => true,
            'default' => 1,
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
        $table->addIndex(['user_id'], 'requrvhive_prompt_user_idx');
    }

    private function createCoworkersTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('requrvhive_coworkers')) {
            return;
        }

        $table = $schema->createTable('requrvhive_coworkers');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('title', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('description', Types::STRING, [
            'notnull' => false,
            'length' => 1000,
        ]);
        $table->addColumn('prompt_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('custom_prompt', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('model', Types::STRING, [
            'notnull' => false,
            'length' => 100,
        ]);
        $table->addColumn('cron_schedule', Types::STRING, [
            'notnull' => true,
            'length' => 100,
        ]);
        $table->addColumn('input_type', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('input_path', Types::STRING, [
            'notnull' => false,
            'length' => 4000,
        ]);
        $table->addColumn('output_type', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('output_path', Types::STRING, [
            'notnull' => false,
            'length' => 4000,
        ]);
        $table->addColumn('is_active', Types::SMALLINT, [
            'notnull' => true,
            'default' => 1,
        ]);
        $table->addColumn('last_run_at', Types::BIGINT, [
            'notnull' => false,
        ]);
        $table->addColumn('next_run_at', Types::BIGINT, [
            'notnull' => false,
        ]);
        $table->addColumn('last_status', Types::STRING, [
            'notnull' => false,
            'length' => 16,
        ]);
        $table->addColumn('last_error', Types::TEXT, [
            'notnull' => false,
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
        $table->addIndex(['user_id'], 'requrvhive_coworker_user_idx');
        $table->addIndex(['next_run_at', 'is_active'], 'requrvhive_coworker_run_idx');
    }
}
