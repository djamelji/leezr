<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\PaymentOrchestrator;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

class CompanyBillingReadService
{
    /**
     * Available payment methods for this company's context.
     * Resolves based on company's market_key and plan_key.
     */
    public static function availablePaymentMethods(Company $company): array
    {
        return PaymentOrchestrator::resolveMethodsForContext(
            marketKey: $company->market_key,
            planKey: CompanyEntitlements::planKey($company),
        );
    }

    /**
     * Stub: invoice list.
     * Returns empty array — future integration with payment providers.
     */
    public static function invoices(Company $company): array
    {
        return [];
    }

    /**
     * Stub: payment history.
     * Returns empty array — future integration with payment providers.
     */
    public static function payments(Company $company): array
    {
        return [];
    }

    /**
     * Current active subscription for the company.
     */
    public static function currentSubscription(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'status' => $subscription->status,
            'provider' => $subscription->provider,
            'current_period_start' => $subscription->current_period_start?->toISOString(),
            'current_period_end' => $subscription->current_period_end?->toISOString(),
            'created_at' => $subscription->created_at->toISOString(),
        ];
    }

    /**
     * Billing portal URL.
     * Returns null for internal provider. Future: delegate to adapter.
     */
    public static function portalUrl(Company $company): ?string
    {
        return null;
    }
}
