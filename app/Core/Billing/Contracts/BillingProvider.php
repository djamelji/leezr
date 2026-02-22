<?php

namespace App\Core\Billing\Contracts;

use App\Core\Models\Company;

interface BillingProvider
{
    /**
     * Create or retrieve customer on the billing platform.
     *
     * @return string External customer ID (e.g. Stripe customer ID)
     */
    public function ensureCustomer(Company $company): string;

    /**
     * Change company's plan (creates/updates subscription).
     */
    public function changePlan(Company $company, string $planKey): void;

    /**
     * Cancel active subscription.
     */
    public function cancelSubscription(Company $company): void;

    /**
     * Get billing portal URL for self-service management.
     */
    public function billingPortalUrl(Company $company): ?string;

    /**
     * Handle incoming webhook payload.
     *
     * @return array|null Associative array with side-effects (e.g. ['plan_key' => 'pro', 'company_id' => 1]), or null if no action needed
     */
    public function handleWebhook(array $payload, string $signature): ?array;
}
