<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service\Provider;

use OCP\IConfig;

/**
 * Resolves the active LLM provider for the chat experience.
 *
 * ReQurv Hive ships a single provider — ReQurv AI Hive (hive.requrv.ai) — but
 * the factory keeps the multi-provider shape (per-user override, admin
 * default, access checks) so behaviour stays uniform and future providers can
 * be added without touching the controllers.
 *
 * ## Access control
 *
 * Every candidate at every level is run through ProviderAccessService, so an
 * admin's per-user/group rules apply to the user override, the instance
 * default and any pinned id alike. A denied candidate fails closed with
 * NoPermittedProviderException.
 *
 * A null user id means a system context (CLI, background job, admin settings)
 * and is never restricted.
 */
class LLMProviderFactory {
    private const APP_NAME = 'requrvhive';
    public const DEFAULT_PROVIDER = 'hive';

    public function __construct(
        private readonly IConfig $config,
        private readonly HiveProvider $hive,
        private readonly ProviderAccessService $access,
    ) {
    }

    /** @return array<string, LLMProviderInterface> */
    private function providers(): array {
        return [
            $this->hive->getId() => $this->hive,
        ];
    }

    /** @return list<string> */
    public function getProviderIds(): array {
        return array_keys($this->providers());
    }

    /**
     * The provider ids the given user is permitted to use, in display order.
     *
     * @return list<string>
     */
    public function getProviderIdsForUser(?string $userId = null): array {
        return $this->access->filterAllowed($this->getProviderIds(), $userId);
    }

    /** Whether the user is permitted at least one provider. */
    public function hasPermittedProvider(?string $userId = null): bool {
        return $this->getProviderIdsForUser($userId) !== [];
    }

    /** Whether the given user may use the named provider. */
    public function isAllowedForUser(string $id, ?string $userId = null): bool {
        return $this->isKnownProviderId($id) && $this->access->isAllowed($id, $userId);
    }

    /**
     * The provider id that should serve the given user.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getActiveProviderId(?string $userId = null): string {
        $permitted = $this->getProviderIdsForUser($userId);
        if ($permitted === []) {
            throw new NoPermittedProviderException($userId);
        }

        if ($userId !== null) {
            $userProvider = $this->config->getUserValue($userId, self::APP_NAME, 'user_provider', '');
            if ($userProvider !== '' && in_array($userProvider, $permitted, true)) {
                return $userProvider;
            }
        }

        $adminDefault = $this->config->getAppValue(self::APP_NAME, 'provider', self::DEFAULT_PROVIDER);
        if (in_array($adminDefault, $permitted, true)) {
            return $adminDefault;
        }

        // The instance default is denied for this user (or unknown): fall
        // through to the first provider they may actually use rather than
        // handing back one they cannot.
        if (in_array(self::DEFAULT_PROVIDER, $permitted, true)) {
            return self::DEFAULT_PROVIDER;
        }
        return $permitted[0];
    }

    /**
     * The provider that should serve the given user.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getProvider(?string $userId = null): LLMProviderInterface {
        return $this->getProviderById($this->getActiveProviderId($userId));
    }

    /**
     * The provider that should serve a specific request for a user.
     *
     * Use this — not getProviderById() — wherever the id comes from a pin
     * (conversation, coworker) that was stored earlier: permissions can be
     * revoked after the pin was made, and a revoked pin has to degrade to the
     * user's current provider rather than continue to be honoured.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getProviderForUser(?string $userId, ?string $requestedId): LLMProviderInterface {
        if ($requestedId !== null && $requestedId !== '' && $this->isAllowedForUser($requestedId, $userId)) {
            return $this->getProviderById($requestedId);
        }
        return $this->getProvider($userId);
    }

    /**
     * Whether the id names a registered provider.
     *
     * Callers that accept a provider id from a request MUST check this before
     * doing anything with it — getProviderById() silently falls back to the
     * default provider, which is right for a stale config value but would
     * mask a bad request and let an arbitrary string reach persistence.
     */
    public function isKnownProviderId(string $id): bool {
        return isset($this->providers()[$id]);
    }

    /**
     * Look up a specific provider by id (falls back to the default provider).
     */
    public function getProviderById(string $id): LLMProviderInterface {
        return $this->providers()[$id] ?? $this->hive;
    }

    /**
     * Metadata for settings UIs: each provider's id, label, and whether a key
     * is configured (user or admin scope) for the given user.
     *
     * @return list<array{id: string, label: string, configured: bool}>
     */
    public function describeProviders(?string $userId = null): array {
        $out = [];
        foreach ($this->providers() as $provider) {
            $out[] = [
                'id' => $provider->getId(),
                'label' => $provider->getLabel(),
                'configured' => $provider->isConfigured($userId),
            ];
        }
        return $out;
    }
}
