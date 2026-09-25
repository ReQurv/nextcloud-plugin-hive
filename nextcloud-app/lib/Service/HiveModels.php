<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

/**
 * Model registry for the ReQurv AI Hive endpoint.
 *
 * The live /models listing is authoritative; this class only carries the
 * fallback the settings UI shows when the endpoint cannot be reached.
 */
final class HiveModels {
    /** Model id used when the admin has not configured one yet. */
    public const DEFAULT_MODEL = 'requrv-small-3.8';

    public const DEFAULT_MAX_TOKENS = 8192;

    /**
     * Static fallback model list. Kept intentionally small — the hive
     * line-up is managed on our side and the live listing is the source of
     * truth.
     *
     * @return list<string>
     */
    public static function getAllModels(): array {
        return [self::DEFAULT_MODEL];
    }
}
