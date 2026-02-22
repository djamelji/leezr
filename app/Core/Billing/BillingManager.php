<?php

namespace App\Core\Billing;

use Illuminate\Support\Manager;

class BillingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('billing.driver', 'null');
    }

    protected function createNullDriver(): NullBillingProvider
    {
        return new NullBillingProvider();
    }

    protected function createStripeDriver(): StripeBillingProvider
    {
        return new StripeBillingProvider(
            $this->config->get('billing.stripe', []),
        );
    }
}
