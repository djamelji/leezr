<?php

namespace Database\Seeders;

use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use Illuminate\Database\Seeder;

/**
 * Seeds the internal payment module and a default manual payment rule.
 * Idempotent: uses updateOrCreate.
 */
class PaymentModuleSeeder extends Seeder
{
    public function run(): void
    {
        // Internal payment module (always installed + active)
        PlatformPaymentModule::updateOrCreate(
            ['provider_key' => 'internal'],
            [
                'name' => 'Internal (Manual)',
                'description' => 'Built-in manual payment processing — no external provider required.',
                'is_installed' => true,
                'is_active' => true,
                'health_status' => 'healthy',
                'sort_order' => 0,
            ],
        );

        // Default rule: manual method via internal provider
        PlatformPaymentMethodRule::updateOrCreate(
            [
                'method_key' => 'manual',
                'provider_key' => 'internal',
                'market_key' => null,
                'plan_key' => null,
                'interval' => null,
            ],
            [
                'priority' => 0,
                'is_active' => true,
            ],
        );
    }
}
