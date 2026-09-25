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
 * Cowork engine: adds an owner_app column so other Nextcloud apps can register
 * and steer their own coworkers (via the public ICoworkManager) without those
 * jobs appearing in the user-facing UI/MCP list. A NULL owner_app means the
 * coworker is user-owned.
 */
class Version0003Date20260612120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('requrvhive_coworkers')) {
            return null;
        }

        $table = $schema->getTable('requrvhive_coworkers');

        if (!$table->hasColumn('owner_app')) {
            $table->addColumn('owner_app', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
        }
        if (!$table->hasIndex('requrvhive_cw_ownerapp_idx')) {
            $table->addIndex(['owner_app'], 'requrvhive_cw_ownerapp_idx');
        }

        return $schema;
    }
}
