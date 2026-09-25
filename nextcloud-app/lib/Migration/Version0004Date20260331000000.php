<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0004Date20260331000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        $changed = false;

        // 1. Create requrvhive_projects table
        if (!$schema->hasTable('requrvhive_projects')) {
            $table = $schema->createTable('requrvhive_projects');
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
            $table->addColumn('system_prompt', Types::TEXT, [
                'notnull' => false,
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
            $table->addIndex(['user_id'], 'requrvhive_proj_user_idx');
            $changed = true;
        }

        // 2. Create requrvhive_project_paths table
        if (!$schema->hasTable('requrvhive_project_paths')) {
            $table = $schema->createTable('requrvhive_project_paths');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('project_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);
            $table->addColumn('path_type', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id'], 'requrvhive_projpath_proj_idx');
            $changed = true;
        }

        // 3. Add project_id to conversations
        if ($schema->hasTable('requrvhive_conversations')) {
            $table = $schema->getTable('requrvhive_conversations');
            if (!$table->hasColumn('project_id')) {
                $table->addColumn('project_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        // 4. Add latency_ms to messages
        if ($schema->hasTable('requrvhive_messages')) {
            $table = $schema->getTable('requrvhive_messages');
            if (!$table->hasColumn('latency_ms')) {
                $table->addColumn('latency_ms', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
