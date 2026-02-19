<?php

namespace App\Core\Modules;

use App\Modules\Core\Members\MembersModule;
use App\Modules\Core\Settings\SettingsModule;
use App\Modules\Logistics\Shipments\ShipmentsModule;
use App\Modules\Platform\Audience\AudienceModule;
use App\Modules\Platform\Billing\BillingModule;
use App\Modules\Platform\Companies\CompaniesModule;
use App\Modules\Platform\Settings\PlatformSettingsModule;
use App\Modules\Platform\Dashboard\DashboardModule;
use App\Modules\Platform\Fields\FieldsModule;
use App\Modules\Platform\Jobdomains\JobdomainsModule;
use App\Modules\Platform\Modules\ModulesModule;
use App\Modules\Platform\Roles\RolesModule;
use App\Modules\Platform\Users\UsersModule;

/**
 * Aggregator loading module manifests from per-module classes.
 * Single source of truth for module definitions.
 * Modules are seeded into platform_modules via sync().
 */
class ModuleRegistry
{
    /** @var array<class-string<ModuleDefinition>> */
    private static array $modules = [
        // Company-scope modules
        MembersModule::class,
        SettingsModule::class,
        ShipmentsModule::class,

        // Platform-scope modules
        DashboardModule::class,
        CompaniesModule::class,
        UsersModule::class,
        RolesModule::class,
        ModulesModule::class,
        JobdomainsModule::class,
        FieldsModule::class,
        PlatformSettingsModule::class,
        AudienceModule::class,
        BillingModule::class,
    ];

    /** @var array<string, ModuleManifest>|null */
    private static ?array $cache = null;

    /**
     * All module definitions, keyed by module key.
     *
     * @return array<string, ModuleManifest>
     */
    public static function definitions(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $manifests = [];

        foreach (static::$modules as $class) {
            $manifest = $class::manifest();
            $manifests[$manifest->key] = $manifest;
        }

        static::$cache = $manifests;

        return $manifests;
    }

    /**
     * Module definitions filtered by scope.
     *
     * @return array<string, ModuleManifest>
     */
    public static function forScope(string $scope): array
    {
        return array_filter(
            static::definitions(),
            fn (ModuleManifest $m) => $m->scope === $scope,
        );
    }

    /**
     * Get capabilities for a given module key.
     */
    public static function capabilities(string $key): ?Capabilities
    {
        $manifest = static::definitions()[$key] ?? null;

        return $manifest?->capabilities;
    }

    /**
     * Resolve bundle keys to permission keys (company-scope only).
     * Returns a unique list of permission keys for the given bundle keys.
     */
    public static function resolveBundles(array $bundleKeys): array
    {
        $permissionKeys = [];

        foreach (static::forScope('company') as $manifest) {
            foreach ($manifest->bundles as $bundle) {
                if (in_array($bundle['key'], $bundleKeys, true)) {
                    $permissionKeys = array_merge($permissionKeys, $bundle['permissions']);
                }
            }
        }

        return array_unique($permissionKeys);
    }

    /**
     * All valid bundle keys across company-scope modules.
     */
    public static function allBundleKeys(): array
    {
        $keys = [];

        foreach (static::forScope('company') as $manifest) {
            foreach ($manifest->bundles as $bundle) {
                $keys[] = $bundle['key'];
            }
        }

        return $keys;
    }

    /**
     * Sync all module definitions to the platform_modules table.
     * Called from seeder or artisan command.
     */
    public static function sync(): void
    {
        foreach (static::definitions() as $key => $manifest) {
            PlatformModule::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $manifest->name,
                    'description' => $manifest->description,
                    'sort_order' => $manifest->sortOrder,
                    'is_enabled_globally' => PlatformModule::where('key', $key)->value('is_enabled_globally') ?? true,
                ],
            );
        }
    }

    /**
     * Clear the cached manifests (for testing).
     */
    public static function clearCache(): void
    {
        static::$cache = null;
    }
}
