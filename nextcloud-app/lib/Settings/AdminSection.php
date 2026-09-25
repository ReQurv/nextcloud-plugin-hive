<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\RequrvHive\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
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
