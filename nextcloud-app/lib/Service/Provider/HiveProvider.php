<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service\Provider;

use OCA\RequrvHive\Service\HiveModels;

/**
 * ReQurv AI Hive provider (https://hive.requrv.ai).
 *
 * Hive is ReQurv's own inference endpoint, serving models over an
 * OpenAI-compatible REST API, so all wire-format handling comes from
 * AbstractOpenAiCompatibleProvider. Specifics:
 *   - The API key is an instance-level credential: one key, configured by
 *     the admin, shared by every user. Users cannot set a personal key.
 *   - The live /models listing is authoritative; HiveModels is the fallback.
 *   - The base URL is overridable through the app config key `hive_base_url`
 *     (admin-scope only — the server makes outbound requests to whatever is
 *     configured, so a user-settable endpoint would be an SSRF vector).
 */
class HiveProvider extends AbstractOpenAiCompatibleProvider {
    private const PROVIDER_ID = 'hive';

    public const DEFAULT_API_BASE = 'https://hive.requrv.ai/v1';

    public function getId(): string {
        return self::PROVIDER_ID;
    }

    public function getLabel(): string {
        return 'ReQurv AI Hive';
    }

    protected function apiBase(): string {
        $configured = self::normalizeBaseUrl($this->config->getAppValue(self::APP_NAME, 'hive_base_url', ''));
        return $configured !== '' ? $configured : self::DEFAULT_API_BASE;
    }

    /**
     * Normalize an admin-entered endpoint: trim, drop the trailing slash and
     * require an http(s) URL with a host. Non-http(s) input yields '' so the
     * default endpoint is used.
     */
    public static function normalizeBaseUrl(string $raw): string {
        $url = rtrim(trim($raw), '/');
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        if (parse_url($url, PHP_URL_HOST) === null) {
            return '';
        }
        return $url;
    }

    /**
     * The hive key is a single instance credential: a personal key does not
     * exist, so resolution always runs in app scope.
     */
    public function getApiKey(?string $userId = null): string {
        return $this->credentials->getApiKey(null, $this->getId());
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_' . $this->getId(), '');
            if ($userModel !== '') {
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_' . $this->getId(), HiveModels::DEFAULT_MODEL);
    }

    public function getMaxTokens(?string $userId = null): int {
        return (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_' . $this->getId(), (string)HiveModels::DEFAULT_MAX_TOKENS);
    }

    protected function defaultModel(): string {
        return HiveModels::DEFAULT_MODEL;
    }

    protected function defaultMaxTokens(): int {
        return HiveModels::DEFAULT_MAX_TOKENS;
    }

    /**
     * The API key is admin-only (it is the instance credential), the endpoint
     * override is admin-only, everything else follows the base schema.
     */
    public function getSettingsSchema(): array {
        $schema = parent::getSettingsSchema();
        foreach ($schema as &$field) {
            if (($field['id'] ?? '') === 'api_key') {
                $field['scope'] = ProviderSettingsSchema::SCOPE_ADMIN;
                $field['description'] = "ReQurv AI Hive is served with a single instance-level key. It is stored encrypted in Nextcloud's credential manager and shared by every user.";
            }
        }
        unset($field);
        $schema[] = ProviderSettingsSchema::baseUrl(
            'hive_base_url',
            'API endpoint',
            'Leave blank to use ' . self::DEFAULT_API_BASE . '.',
            self::DEFAULT_API_BASE,
        );
        return $schema;
    }

    /** Hive serves multimodal models. */
    protected function supportsVisionInput(?string $userId = null): bool {
        return true;
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    protected function notConfiguredMessage(): string {
        return 'No ReQurv AI Hive API key configured. Set the instance key in the RequrvHive admin settings.';
    }

    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'ReQurv AI Hive rejected the API key. Check it in the RequrvHive settings.';
        }
        if (stripos($msg, '429') !== false) {
            return 'ReQurv AI Hive rate limit reached. Please try again shortly.';
        }
        if (stripos($msg, '503') !== false || stripos($msg, '502') !== false) {
            return 'ReQurv AI Hive is currently unavailable. Please try again later.';
        }
        return 'ReQurv AI Hive error: ' . $msg;
    }
}
