<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCA\RequrvHive\BackgroundJob\CoworkerRunJob;
use OCA\RequrvHive\BackgroundJob\IndexConversationJob;
use OCA\RequrvHive\BackgroundJob\TalkReplyJob;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Removes everything ReQurvHive writes outside its own app directory.
 *
 * Nextcloud's `occ app:remove` only deletes the app folder: the app's
 * credentials, appconfig values (including the Talk bot secret), user
 * preferences, queued jobs and the requrvhive_* tables all survive it, so a
 * reinstall starts with the old — often broken — configuration. This service
 * is the single place that knows the full footprint. The uninstall repair
 * step calls resetConfiguration(); the cleanup command can additionally call
 * purgeData().
 */
class CleanupService {
    private const APP_ID = 'requrvhive';

    /**
     * Tables the app owns, in drop order (children before parents). Kept in
     * sync with lib/Migration on purpose: a repair step must not depend on
     * the migration classes, and the list is stable by design.
     */
    private const DATA_TABLES = [
        'requrvhive_coworker_runs',
        'requrvhive_messages',
        'requrvhive_message_files',
        'requrvhive_conversations',
        'requrvhive_usage_stats',
        'requrvhive_prompts',
        'requrvhive_coworkers',
        'requrvhive_mcp_servers',
        'requrvhive_project_paths',
        'requrvhive_projects',
        'requrvhive_provider_access',
        'requrvhive_talk_bindings',
    ];

    /** Job classes stored in the shared `jobs` table. */
    private const JOB_CLASSES = [
        CoworkerRunJob::class,
        IndexConversationJob::class,
        TalkReplyJob::class,
    ];

    public function __construct(
        private IDBConnection $db,
        private ICacheFactory $cacheFactory,
        private LLMProviderFactory $providerFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Delete credentials, configuration, user preferences, queued jobs and
     * cached state. Safe on a disabled app: it only touches rows owned by
     * this app, and everything it removes is regenerated on demand.
     *
     * @return array<string, int> rows removed per area
     */
    public function resetConfiguration(): array {
        $counts = [
            'credentials' => $this->deleteCredentials(),
            'appconfig' => $this->deleteAppConfig(),
            'preferences' => $this->deletePreferences(),
            'jobs' => $this->deleteJobs(),
        ];
        $this->clearCaches();
        $this->logger->info('RequrvHive: configuration and credentials removed', $counts);

        return $counts;
    }

    /**
     * Drop every table the app owns. Irreversible — the cleanup command
     * gates this behind an explicit flag.
     *
     * @return list<string> tables dropped
     */
    public function purgeData(): array {
        $dropped = [];
        foreach (self::DATA_TABLES as $table) {
            if (!$this->db->tableExists($table)) {
                continue;
            }
            $this->db->dropTable($table);
            $dropped[] = $table;
        }
        $this->logger->warning('RequrvHive: app data purged', ['tables' => $dropped]);

        return $dropped;
    }

    /**
     * Every credential slot the app uses lives under the `requrvhive/`
     * identifier prefix in the shared credentials table (the legacy
     * ICredentialsManager layout: requrvhive/api_key,
     * requrvhive/api_key/<provider>, requrvhive/secret/...). Deleting by
     * prefix reaches app- and user-scope rows in one sweep, whatever the
     * provider list ever was.
     */
    private function deleteCredentials(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('storages_credentials')
            ->where($qb->expr()->like(
                'identifier',
                $qb->createNamedParameter(self::APP_ID . '/%')
            ));

        return $qb->executeStatement();
    }

    /** All instance-level settings: keys, models, the Talk bot secret, ... */
    private function deleteAppConfig(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('appconfig')
            ->where($qb->expr()->eq(
                'appid',
                $qb->createNamedParameter(self::APP_ID)
            ));

        return $qb->executeStatement();
    }

    /** Per-user preferences stored through IConfig::setUserValue(). */
    private function deletePreferences(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('preferences')
            ->where($qb->expr()->eq(
                'appid',
                $qb->createNamedParameter(self::APP_ID)
            ));

        return $qb->executeStatement();
    }

    private function deleteJobs(): int {
        $removed = 0;
        foreach (self::JOB_CLASSES as $class) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('jobs')
                ->where($qb->expr()->eq(
                    'class',
                    $qb->createNamedParameter($class)
                ));
            $removed += $qb->executeStatement();
        }

        return $removed;
    }

    /**
     * Model lists and status probes are cached per provider in the
     * `requrvhive-models-<id>` namespace (see ProviderSettingsService); they
     * must not outlive the configuration they were computed from.
     */
    private function clearCaches(): void {
        foreach ($this->providerFactory->getProviderIds() as $providerId) {
            $this->cacheFactory->createDistributed('requrvhive-models-' . $providerId)->clear();
        }
    }
}
