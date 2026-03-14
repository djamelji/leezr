<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformSetting;
use Illuminate\Support\Manager;

/**
 * Payment gateway driver manager.
 * Reads active driver from platform_settings.billing (DB-configurable).
 * Fallback to 'null' driver when no config or during migrations.
 *
 * Available drivers are discovered dynamically:
 * - 'null' is always available (built-in)
 * - Other drivers discovered via module.json "provides_payment_driver" field
 *
 * @see BillingManager for the existing billing abstraction (admin plan changes)
 */
class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        try {
            $settings = PlatformSetting::instance();
            $driver = $settings->billing['driver'] ?? null;

            if ($driver) {
                return $driver;
            }

            // ADR-301: Fallback to active PlatformPaymentModule provider
            $activeModule = PlatformPaymentModule::active()
                ->orderByDesc('sort_order')
                ->first();

            return $activeModule?->provider_key ?? 'null';
        } catch (\Throwable) {
            return 'null';
        }
    }

    protected function createNullDriver(): NullPaymentGateway
    {
        return new NullPaymentGateway();
    }

    protected function createStripeDriver(): Adapters\StripePaymentAdapter
    {
        return app(Adapters\StripePaymentAdapter::class);
    }

    protected function createInternalDriver(): Adapters\InternalPaymentAdapter
    {
        return app(Adapters\InternalPaymentAdapter::class);
    }

    /**
     * ADR-336: Centralized adapter resolution by provider key.
     *
     * Replaces 5+ hardcoded match statements scattered across services.
     * Uses the Manager's driver() method so new providers are auto-discovered.
     */
    public static function adapterFor(string $providerKey): ?PaymentProviderAdapter
    {
        try {
            $driver = app(self::class)->driver($providerKey);

            return $driver instanceof PaymentProviderAdapter ? $driver : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Dynamically detect available payment gateway providers.
     * Scans module.json for "provides_payment_driver" field.
     *
     * @return array<array{key: string, name: string, installed: bool, module_key?: string}>
     */
    public function availableProviders(): array
    {
        $providers = [
            ['key' => 'null', 'name' => 'Internal (No Payment)', 'installed' => true],
        ];

        try {
            foreach (ModuleRegistry::definitions() as $key => $manifest) {
                $modulePath = ModuleRegistry::modulePath($key);

                if (!$modulePath) {
                    continue;
                }

                $moduleJson = $modulePath . '/module.json';

                if (!file_exists($moduleJson)) {
                    continue;
                }

                $meta = json_decode(file_get_contents($moduleJson), true);

                if (!empty($meta['provides_payment_driver'])) {
                    $providers[] = [
                        'key' => $meta['provides_payment_driver'],
                        'name' => $manifest->name,
                        'installed' => true,
                        'module_key' => $key,
                    ];
                }
            }
        } catch (\Throwable) {
            // Silently fail during early boot / before module table exists
        }

        return $providers;
    }
}
