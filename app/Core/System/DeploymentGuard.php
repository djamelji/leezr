<?php

namespace App\Core\System;

use App\Console\Recipe\RecipeDatabaseGuard;
use App\Core\Models\Company;
use RuntimeException;

/**
 * ADR-833 — deployment safety guards.
 *
 * 1. Legacy release: the historical webhook (public/webhook.php → deploy.sh) clones the pushed branch into
 *    `releases/YYYYMMDD_HHMMSS` and runs artisan from there (migrate, SystemSeeder, DevSeeder) in parallel with the
 *    GitHub Actions pipeline, whose releases are named `YYYYMMDDHHMMSS[_sha]`. Because the legacy script runs the
 *    artisan of the FRESHLY CLONED code, refusing every artisan command from such a directory neutralises the old
 *    pipeline at its first artisan call (composer's package:discover), before any database write — even while an
 *    old deploy.sh is still the one being executed.
 * 2. Demo seed: DevSeeder rewrites demo users, role presets, subscriptions and activations with updateOrCreate.
 *    It may only run where that is harmless: local/testing, an EMPTY database (first population, dev:reset after
 *    its wipe) or a marked recipe clone. Never in production, never on a populated remote database.
 */
final class DeploymentGuard
{
    public const LEGACY_RELEASE_PATTERN = '#/releases/\d{8}_\d{6}/?$#';

    public static function isLegacyRelease(string $basePath): bool
    {
        return preg_match(self::LEGACY_RELEASE_PATTERN, $basePath) === 1;
    }

    public static function assertNotLegacyRelease(string $basePath, string $command): void
    {
        if (self::isLegacyRelease($basePath)) {
            throw new RuntimeException(
                "ADR-833: artisan '{$command}' refused from legacy release {$basePath}. "
                .'The webhook/deploy.sh pipeline is retired; deployments run only through GitHub Actions (deploy/deploy_release.sh).'
            );
        }
    }

    /**
     * Pure decision (unit-tested).
     *
     * @return list<string> reasons of refusal, empty when allowed
     */
    public static function demoSeedRefusals(string $environment, int $companyCount, bool $isRecipeClone): array
    {
        if ($environment === 'production') {
            return ['APP_ENV is production'];
        }
        if (in_array($environment, ['local', 'testing'], true) || $companyCount === 0 || $isRecipeClone) {
            return [];
        }

        return ["APP_ENV={$environment} with {$companyCount} existing companies and no recipe-clone marker"];
    }

    public static function assertDemoSeedAllowed(): void
    {
        $environment = (string) app()->environment();
        $count = $environment === 'production' ? 0 : Company::withoutGlobalScopes()->count();
        $clone = ! in_array($environment, ['production', 'local', 'testing'], true) && $count > 0
            && app(RecipeDatabaseGuard::class)->assess('db:seed DevSeeder')['allowed'];

        $reasons = self::demoSeedRefusals($environment, $count, $clone);
        if ($reasons !== []) {
            throw new RuntimeException('ADR-833: DevSeeder refused — '.implode('; ', $reasons)
                .'. Demo data only seeds local/testing, an empty database or a marked recipe clone.');
        }
    }
}
