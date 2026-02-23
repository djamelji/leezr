<?php

namespace App\Modules\Payments\Stripe;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * Stripe payment gateway module — stub.
 * Declares that this module provides the 'stripe' payment driver.
 * Actual Stripe SDK integration deferred to ADR-102.
 *
 * Driver registration will happen via service provider hook when module is enabled.
 */
class StripePaymentModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'payments.stripe',
            name: 'Stripe Payment Gateway',
            description: 'Stripe integration for subscriptions and payments',
            surface: 'structure',
            sortOrder: 80,
            capabilities: new Capabilities(),
            permissions: [],
            bundles: [],
            scope: 'platform',
            type: 'addon',
            visibility: 'hidden',
            iconRef: 'tabler-brand-stripe',
        );
    }
}
