<?php

namespace App\Core\Ai;

use App\Core\Models\Company;

/**
 * ADR-436: AI quota management per company per module per period.
 *
 * Prevents a single company from consuming all AI resources.
 * Checks request count against configurable monthly limits.
 */
final class AiQuotaManager
{
    /**
     * Default monthly quota per module if no plan-specific limit is set.
     */
    private const DEFAULT_MONTHLY_QUOTA = 100;

    /**
     * Check if a company can process an AI request for the given module.
     */
    public static function canProcess(Company $company, string $moduleKey): bool
    {
        $used = self::usageThisMonth($company->id, $moduleKey);
        $limit = self::quotaLimit($company, $moduleKey);

        return $used < $limit;
    }

    /**
     * Get the number of AI requests this month for a company/module.
     */
    public static function usageThisMonth(int $companyId, string $moduleKey): int
    {
        return AiRequestLog::where('company_id', $companyId)
            ->where('module_key', $moduleKey)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Get the monthly quota limit for a company/module.
     *
     * Reads from PlatformSetting.ai.quotas[moduleKey], or default.
     * Plan-based quotas can be added later via CompanyEntitlements.
     */
    public static function quotaLimit(Company $company, string $moduleKey): int
    {
        $quotas = \App\Platform\Models\PlatformSetting::instance()->ai['quotas'] ?? [];

        return (int) ($quotas[$moduleKey] ?? self::DEFAULT_MONTHLY_QUOTA);
    }

    /**
     * Get remaining quota for a company/module this month.
     */
    public static function remaining(int $companyId, string $moduleKey): int
    {
        $company = Company::find($companyId);
        if (!$company) {
            return 0;
        }

        $limit = self::quotaLimit($company, $moduleKey);
        $used = self::usageThisMonth($companyId, $moduleKey);

        return max(0, $limit - $used);
    }
}
