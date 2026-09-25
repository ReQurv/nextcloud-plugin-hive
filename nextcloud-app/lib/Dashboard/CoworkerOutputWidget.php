<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Dashboard;

use OCA\RequrvHive\Template\ViteAssets;

/**
 * Configurable dashboard widget that renders the latest run summary of a
 * user-selected coworker (weather digest, investment watch, hobby feed, …).
 *
 * Unlike the API widgets this one renders client-side: {@see load()} ships the
 * `requrvhive-dashboard` bundle which registers a Vue component via
 * `OCA.Dashboard.register('requrvhive_coworker_output', …)`. The component reads
 * and persists the selected coworker through the dashboard endpoints on
 * {@see \OCA\RequrvHive\Controller\CoworkerController}.
 */
class CoworkerOutputWidget extends AbstractRequrvHiveWidget {
    public function getId(): string {
        return 'requrvhive_coworker_output';
    }

    public function getTitle(): string {
        return $this->l10n->t('RequrvHive coworker');
    }

    public function getOrder(): int {
        return 40;
    }

    protected function hashRoute(): string {
        return '/cowork';
    }

    public function load(): void {
        ViteAssets::load(self::APP_ID . '-dashboard');
    }
}
