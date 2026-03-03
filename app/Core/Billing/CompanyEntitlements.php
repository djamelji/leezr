<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * Single source of truth for company plan key and entitlements.
 *
 * Every consumer (NavBuilder, EntitlementResolver, AuthController, etc.)
 * calls this instead of scattering `plan_key ?? 'starter'`.
 */
final class CompanyEntitlements
{
    public static function planKey(Company $company): string
    {
        return $company->plan_key ?? 'starter';
    }

    /**
     * ADR-172: Member limit for the company's plan (null = unlimited).
     */
    public static function memberLimit(Company $company): ?int
    {
        $planKey = static::planKey($company);
        $definitions = PlanRegistry::definitions();
        $limit = $definitions[$planKey]['limits']['members'] ?? null;

        return $limit !== null ? (int) $limit : null;
    }

    /**
     * ADR-169 Phase 4: Storage quota in GB for the company's plan.
     */
    public static function storageQuotaGb(Company $company): int
    {
        $planKey = static::planKey($company);
        $definitions = PlanRegistry::definitions();

        return $definitions[$planKey]['limits']['storage_quota_gb'] ?? 1;
    }
}
