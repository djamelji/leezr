<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Models\Company;

/**
 * Default billing driver — no external service.
 * changePlan() writes plan_key directly to DB (same as platform admin behavior).
 * All other methods are no-ops.
 */
class NullBillingProvider implements BillingProvider
{
    public function ensureCustomer(Company $company): string
    {
        return 'null_' . $company->id;
    }

    public function changePlan(Company $company, string $planKey): void
    {
        $company->update(['plan_key' => $planKey]);
    }

    public function cancelSubscription(Company $company): void
    {
        // No-op — no external subscription to cancel
    }

    public function billingPortalUrl(Company $company): ?string
    {
        return null;
    }

    public function handleWebhook(array $payload, string $signature): ?array
    {
        return null;
    }
}
