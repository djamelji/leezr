<?php

namespace App\Modules\Platform\Billing;

use App\Core\Billing\FinancialPeriod;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Platform\Models\PlatformSetting;

/**
 * Thin CRUD service for trivial billing config operations.
 * No invariants, no audit, no side-effects — just keeps Eloquent out of controllers.
 */
class BillingConfigCrudService
{
    public static function updateConfig(array $validated): array
    {
        $settings = PlatformSetting::instance();
        $settings->update(['billing' => $validated]);

        return $settings->billing;
    }

    public static function updatePolicies(array $policies): array
    {
        $settings = PlatformSetting::instance();
        $billing = $settings->billing ?? ['driver' => 'null', 'config' => []];
        $billing['policies'] = $policies;
        $settings->update(['billing' => $billing]);

        return $policies;
    }

    public static function rejectSubscription(int $id): Subscription
    {
        $subscription = Subscription::where('status', 'pending')->findOrFail($id);
        $subscription->update(['status' => 'rejected']);

        return $subscription->fresh()->load('company:id,name,slug');
    }

    public static function closePeriod(int $companyId, string $startDate, string $endDate): FinancialPeriod
    {
        return FinancialPeriod::updateOrCreate(
            [
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            [
                'is_closed' => true,
                'closed_at' => now(),
            ],
        );
    }

    public static function findCompany(int $id): ?Company
    {
        return Company::find($id);
    }

    public static function toggleFreeze(int $companyId, bool $frozen): Company
    {
        $company = Company::findOrFail($companyId);
        $company->update(['financial_freeze' => $frozen]);

        return $company;
    }
}
