<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0003Date20260320000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        $changed = false;

        if ($schema->hasTable('requrvhive_usage_stats')) {
            $table = $schema->getTable('requrvhive_usage_stats');
            if (!$table->hasColumn('cache_creation_tokens')) {
                $table->addColumn('cache_creation_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('cache_read_tokens')) {
                $table->addColumn('cache_read_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        if ($schema->hasTable('requrvhive_messages')) {
            $table = $schema->getTable('requrvhive_messages');
            if (!$table->hasColumn('cache_creation_tokens')) {
                $table->addColumn('cache_creation_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('cache_read_tokens')) {
                $table->addColumn('cache_read_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
