<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\RepairSteps;

use OCA\RequrvHive\Service\CleanupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs on uninstall (and on every disable, which Nextcloud treats the same):
 * removes credentials, configuration, preferences, queued jobs and cached
 * state, so a reinstall starts clean instead of inheriting the old — often
 * broken — key and the old Talk bot secret.
 *
 * App data (conversations, projects, ...) is deliberately left in place: a
 * disable can be temporary, and wiping user data needs the explicit
 * `occ requrvhive:cleanup --with-data`.
 */
class UninstallCleanup implements IRepairStep {
    public function __construct(
        private CleanupService $cleanup,
    ) {
    }

    public function getName(): string {
        return 'Remove ReQurvHive configuration and credentials';
    }

    public function run(IOutput $output): void {
        $counts = $this->cleanup->resetConfiguration();
        $output->info(sprintf(
            'Removed ReQurvHive configuration: %d credential(s), %d app setting(s), %d user preference(s), %d queued job(s)',
            $counts['credentials'],
            $counts['appconfig'],
            $counts['preferences'],
            $counts['jobs'],
        ));
    }
}
