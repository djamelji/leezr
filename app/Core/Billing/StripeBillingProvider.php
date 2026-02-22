<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Models\Company;
use RuntimeException;

/**
 * Stripe billing driver — stub.
 * Every method throws until real Stripe integration is implemented.
 * Stripe SDK dependency will be confined to this single class.
 *
 * @see ADR-011 (billing deferred)
 */
class StripeBillingProvider implements BillingProvider
{
    public function __construct(
        protected readonly array $config,
    ) {}

    public function ensureCustomer(Company $company): string
    {
        throw new RuntimeException('Stripe billing not implemented — see ADR-011.');
    }

    public function changePlan(Company $company, string $planKey): void
    {
        throw new RuntimeException('Stripe billing not implemented — see ADR-011.');
    }

    public function cancelSubscription(Company $company): void
    {
        throw new RuntimeException('Stripe billing not implemented — see ADR-011.');
    }

    public function billingPortalUrl(Company $company): ?string
    {
        throw new RuntimeException('Stripe billing not implemented — see ADR-011.');
    }

    public function handleWebhook(array $payload, string $signature): ?array
    {
        throw new RuntimeException('Stripe billing not implemented — see ADR-011.');
    }
}
