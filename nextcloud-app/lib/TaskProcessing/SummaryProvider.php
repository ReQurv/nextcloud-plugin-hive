<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\TaskProcessing;

use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;

/**
 * Summary TaskProcessing Provider (core:text2text:summary)
 */
class SummaryProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'requrvhive:text2text:summary';
    }

    public function getName(): string {
        return 'RequrvHive';
    }

    public function getTaskTypeId(): string {
        return TextToTextSummary::ID;
    }

    public function getExpectedRuntime(): int {
        // A single model round-trip: typically a few seconds, can stretch to a
        // couple of minutes under load. The framework uses this to size its
        // job-runner timeout.
        return 120;
    }

    public function getOptionalInputShape(): array {
        return [];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalInputShapeDefaults(): array {
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $text = $input['input'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }

        $provider = $this->providers->resolve($userId);

        $reportProgress(0.1);
        $result = $provider->ask("Summarize the following content concisely:\n\n" . $text, '', $userId);

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
