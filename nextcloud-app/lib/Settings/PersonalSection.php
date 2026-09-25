<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Personal counterpart of {@see AdminSection}.
 *
 * Nextcloud keeps admin and personal sections in separate registries, so the
 * `requrvhive` id registered for the admin area does not place the personal form —
 * without this class /settings/user/requrvhive 404s and the entry never shows up
 * in the personal settings navigation.
 */
class PersonalSection implements IIconSection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'requrvhive';
    }

    public function getName(): string {
        return $this->l->t('RequrvHive');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('requrvhive', 'app-dark.svg');
    }
}
