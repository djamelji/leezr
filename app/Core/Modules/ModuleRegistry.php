<?php

namespace App\Core\Modules;

use App\Modules\Core\Members\MembersModule;
use App\Modules\Core\Settings\SettingsModule;
use App\Modules\Logistics\Shipments\ShipmentsModule;

/**
 * Aggregator loading module manifests from per-module classes.
 * Single source of truth for module definitions.
 * Modules are seeded into platform_modules via sync().
 */
class ModuleRegistry
{
    /** @var array<class-string<ModuleDefinition>> */
    private static array $modules = [
        MembersModule::class,
        SettingsModule::class,
        ShipmentsModule::class,
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
     * Get capabilities for a given module key.
     */
    public static function capabilities(string $key): ?Capabilities
    {
        $manifest = static::definitions()[$key] ?? null;

        return $manifest?->capabilities;
    }

    /**
     * Resolve bundle keys to permission keys.
     * Returns a unique list of permission keys for the given bundle keys.
     */
    public static function resolveBundles(array $bundleKeys): array
    {
        $permissionKeys = [];

        foreach (static::definitions() as $manifest) {
            foreach ($manifest->bundles as $bundle) {
                if (in_array($bundle['key'], $bundleKeys, true)) {
                    $permissionKeys = array_merge($permissionKeys, $bundle['permissions']);
                }
            }
        }

        return array_unique($permissionKeys);
    }

    /**
     * All valid bundle keys across all modules.
     */
    public static function allBundleKeys(): array
    {
        $keys = [];

        foreach (static::definitions() as $manifest) {
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
