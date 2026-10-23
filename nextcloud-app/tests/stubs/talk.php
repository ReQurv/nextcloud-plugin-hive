<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stubs for the Talk (spreed) bot events, dispatched by and received from the
 * Talk app.
 *
 * Talk is a separate app installed side by side in the Nextcloud instance, not
 * a Composer dependency, so its `OCA\Talk\*` classes cannot be resolved from
 * vendor/. Every use site is guarded (`class_exists` before dispatch, an
 * `instanceof` check in the listener), so the stubs only need the signatures
 * that are used. Like the other stubs, each class is guarded so that a real
 * class, if ever loadable, wins.
 *
 * @see \OCA\RequrvHive\Talk\TalkBotRegistration
 * @see \OCA\RequrvHive\Talk\TalkBotListener
 */

namespace OCA\Talk\Events;

use OCP\EventDispatcher\Event;

if (!class_exists(BotInstallEvent::class)) {
    class BotInstallEvent extends Event {
        public function __construct(
            private readonly string $name,
            private readonly string $secret,
            private readonly string $url,
            private readonly string $description = '',
            private readonly ?int $features = null,
        ) {
            parent::__construct();
        }

        public function getName(): string {
            return $this->name;
        }

        public function getSecret(): string {
            return $this->secret;
        }

        public function getUrl(): string {
            return $this->url;
        }

        public function getDescription(): string {
            return $this->description;
        }

        public function getFeatures(): int {
            return $this->features ?? 0;
        }
    }
}

if (!class_exists(BotInvokeEvent::class)) {
    class BotInvokeEvent extends Event {
        /**
         * @param array<string, mixed> $message
         */
        public function __construct(
            private readonly string $botUrl,
            private readonly array $message,
        ) {
            parent::__construct();
        }

        public function getBotUrl(): string {
            return $this->botUrl;
        }

        /**
         * @return array<string, mixed>
         */
        public function getMessage(): array {
            return $this->message;
        }
    }
}
