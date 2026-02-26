<?php

namespace App\Core\Billing;

use App\Core\Modules\ModuleRegistry;

/**
 * Static registry of payment module manifests.
 * Boots with built-in 'internal' manifest, then discovers from module.json.
 */
class PaymentRegistry
{
    /** @var array<string, PaymentModuleManifest> */
    private static array $manifests = [];

    public static function register(PaymentModuleManifest $manifest): void
    {
        static::$manifests[$manifest->providerKey] = $manifest;
    }

    /**
     * @return array<string, PaymentModuleManifest>
     */
    public static function all(): array
    {
        return static::$manifests;
    }

    public static function get(string $providerKey): ?PaymentModuleManifest
    {
        return static::$manifests[$providerKey] ?? null;
    }

    /**
     * @return string[]
     */
    public static function supportedMethods(string $providerKey): array
    {
        return static::$manifests[$providerKey]?->supportedMethods ?? [];
    }

    public static function clearCache(): void
    {
        static::$manifests = [];
    }

    /**
     * Boot all payment module manifests.
     * Called from AppServiceProvider::boot().
     */
    public static function boot(): void
    {
        // Built-in internal provider
        static::register(new PaymentModuleManifest(
            providerKey: 'internal',
            name: 'Internal (No Payment)',
            description: 'Manual approval workflow — no external payment processing.',
            supportedMethods: ['manual'],
            iconRef: 'tabler-lock',
        ));

        // Discover from module.json
        static::discoverFromModules();
    }

    private static function discoverFromModules(): void
    {
        try {
            foreach (ModuleRegistry::definitions() as $key => $moduleManifest) {
                $modulePath = ModuleRegistry::modulePath($key);

                if (!$modulePath) {
                    continue;
                }

                $moduleJson = $modulePath . '/module.json';

                if (!file_exists($moduleJson)) {
                    continue;
                }

                $meta = json_decode(file_get_contents($moduleJson), true);

                if (empty($meta['payment_module'])) {
                    continue;
                }

                $pm = $meta['payment_module'];

                static::register(new PaymentModuleManifest(
                    providerKey: $meta['provides_payment_driver'] ?? $pm['provider_key'] ?? $key,
                    name: $pm['name'] ?? $moduleManifest->name,
                    description: $pm['description'] ?? $moduleManifest->description,
                    supportedMethods: $pm['supported_methods'] ?? [],
                    iconRef: $pm['icon'] ?? $moduleManifest->iconRef,
                    requiresCredentials: $pm['requires_credentials'] ?? false,
                    credentialFields: $pm['credential_fields'] ?? [],
                ));
            }
        } catch (\Throwable) {
            // Silently fail during early boot / before module table exists
        }
    }
}
