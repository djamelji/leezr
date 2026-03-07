<?php

namespace App\Core\Billing;

use App\Core\Models\Company;

/**
 * Orchestrates the checkout flow for plan changes.
 * Keeps controllers thin — all checkout logic lives here.
 */
class ChangePlanService
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
    ) {}

    public function requestUpgrade(Company $company, string $planKey, string $interval = 'monthly'): CheckoutResult
    {
        return $this->gatewayManager->driver()->createCheckout($company, $planKey, $interval);
    }
}
