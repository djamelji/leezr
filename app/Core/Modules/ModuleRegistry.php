<?php

namespace App\Core\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Aggregator loading module manifests via autodiscovery.
 * Single source of truth for module definitions.
 * Scans app/Modules/** for classes implementing ModuleDefinition.
 * Modules are seeded into platform_modules via sync().
 */
class ModuleRegistry
{
    /** @var array<string, ModuleManifest>|null Manifest cache keyed by module key */
    private static ?array $cache = null;

    /** @var array<class-string<ModuleDefinition>>|null Discovered module classes */
    private static ?array $discovered = null;

    /** @var array<string, string>|null Module key → FQCN mapping */
    private static ?array $classMap = null;

    /**
     * Discover all module classes implementing ModuleDefinition.
     * Uses recursive filesystem scan (not fragile glob).
     *
     * @return array<class-string<ModuleDefinition>>
     */
    private static function discoverModules(): array
    {
        if (static::$discovered !== null) {
            return static::$discovered;
        }

        $modulesPath = app_path('Modules');
        $modules = [];

        if (!is_dir($modulesPath)) {
            static::$discovered = [];

            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getBasename('.php');

            if (!str_ends_with($filename, 'Module')) {
                continue;
            }

            // Convert file path to FQCN
            $relativePath = str_replace(
                [app_path() . DIRECTORY_SEPARATOR, '.php'],
                ['', ''],
                $file->getRealPath(),
            );
            $className = 'App\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (!$reflection->implementsInterface(ModuleDefinition::class)) {
                continue;
            }

            $modules[] = $className;
        }

        static::$discovered = $modules;

        return $modules;
    }

    /**
     * All module definitions, keyed by module key.
     * Builds both manifest cache and class map in a single pass.
     *
     * @return array<string, ModuleManifest>
     */
    public static function definitions(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $manifests = [];
        $classMap = [];

        foreach (static::discoverModules() as $class) {
            $manifest = $class::manifest();
            $manifests[$manifest->key] = $manifest;
            $classMap[$manifest->key] = $class;
        }

        static::$cache = $manifests;
        static::$classMap = $classMap;

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
     * Get the FQCN of the module class for a given key.
     */
    public static function moduleClass(string $key): ?string
    {
        static::definitions(); // ensure classMap is populated

        return static::$classMap[$key] ?? null;
    }

    /**
     * Get the directory path of a module by its key.
     */
    public static function modulePath(string $key): ?string
    {
        $class = static::moduleClass($key);

        if (!$class) {
            return null;
        }

        return dirname((new ReflectionClass($class))->getFileName());
    }

    /**
     * Clear all caches (for testing or re-discovery).
     */
    public static function clearCache(): void
    {
        static::$cache = null;
        static::$discovered = null;
        static::$classMap = null;
    }
}
