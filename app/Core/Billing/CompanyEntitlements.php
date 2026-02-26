<?php

namespace App\Core\Billing;

use App\Core\Models\Company;

/**
 * Single source of truth for company plan key.
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
}
