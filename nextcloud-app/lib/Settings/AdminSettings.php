<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Mount point for the admin settings Vue app.
 *
 * Provider configuration is fetched from /api/admin/providers, which renders
 * whatever schema each provider declares — so nothing about providers is built
 * here. Only the plain instance booleans are passed through, to spare the page a
 * second round trip for them.
 */
class AdminSettings implements ISettings {
    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('requrvhive', 'admin', [
            'search_enabled' => $this->config->getAppValue('requrvhive', 'search_enabled', '0') === '1',
        ], '');
    }

    public function getSection(): string {
        return 'requrvhive';
    }

    public function getPriority(): int {
        return 10;
    }
}
