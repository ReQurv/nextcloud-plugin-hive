<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0013Date20260918000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('requrvhive_conversations')) {
            return null;
        }

        $table = $schema->getTable('requrvhive_conversations');

        // Null means "no pin" — the instance default decides. False is a
        // deliberate opt-out on an instance that defaults fast mode on, so the
        // column is tri-state rather than a notnull boolean.
        if ($table->hasColumn('speed_fast')) {
            return null;
        }

        $table->addColumn('speed_fast', Types::BOOLEAN, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
