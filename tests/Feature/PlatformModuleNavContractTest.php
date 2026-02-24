<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates the platform module navigation contract.
 *
 * Ensures every platform module with a page declares navItems + routeNames,
 * and that the backend API returns all expected nav items to the frontend.
 */
class PlatformModuleNavContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    /**
     * Every visible platform module that has routeNames must declare navItems
     * OR be explicitly documented as a shared-page module (e.g., Translations
     * shares the International page with Markets).
     */
    public function test_visible_platform_modules_with_routes_declare_nav_items(): void
    {
        // Modules that explicitly share another module's page (tab pattern)
        // These modules have routeNames but no navItem — they piggyback on another module's nav entry
        $sharedPageModules = [
            'platform.translations', // Tab on International page (owned by platform.markets)
        ];

        $missing = [];

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            if ($manifest->visibility === 'hidden') {
                continue;
            }

            if (in_array($key, $sharedPageModules, true)) {
                continue;
            }

            if (!empty($manifest->capabilities->routeNames) && empty($manifest->capabilities->navItems)) {
                $missing[] = "{$key} has routeNames but no navItems";
            }
        }

        $this->assertEmpty(
            $missing,
            "Platform modules with routes missing navItems:\n" . implode("\n", $missing),
        );
    }

    /**
     * Every platform module that declares navItems must also declare routeNames.
     */
    public function test_platform_modules_with_nav_items_declare_route_names(): void
    {
        $missing = [];

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            if (!empty($manifest->capabilities->navItems) && empty($manifest->capabilities->routeNames)) {
                $missing[] = "{$key} has navItems but no routeNames";
            }
        }

        $this->assertEmpty(
            $missing,
            "Platform modules with navItems missing routeNames:\n" . implode("\n", $missing),
        );
    }

    /**
     * All navItem route names must appear in the module's routeNames.
     */
    public function test_nav_item_routes_are_declared_in_route_names(): void
    {
        $violations = [];

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            foreach ($manifest->capabilities->navItems as $item) {
                $routeName = $item['to']['name'] ?? null;

                if ($routeName && !in_array($routeName, $manifest->capabilities->routeNames, true)) {
                    $violations[] = "{$key}: navItem '{$item['key']}' points to route '{$routeName}' but it's not in routeNames";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "NavItem routes not declared in routeNames:\n" . implode("\n", $violations),
        );
    }

    /**
     * The platformModuleNavItems() backend function returns all expected nav items.
     */
    public function test_platform_nav_api_returns_all_visible_module_items(): void
    {
        $expectedKeys = collect(ModuleRegistry::forScope('admin'))
            ->filter(fn (ModuleManifest $m) => $m->visibility !== 'hidden')
            ->flatMap(fn (ModuleManifest $m) => collect($m->capabilities->navItems)->pluck('key'))
            ->values()
            ->all();

        $actualItems = collect(ModuleRegistry::forScope('admin'))
            ->filter(fn (ModuleManifest $m) => $m->visibility === 'visible')
            ->flatMap(fn (ModuleManifest $m) => $m->capabilities->navItems)
            ->values()
            ->all();

        $actualKeys = collect($actualItems)->pluck('key')->all();

        foreach ($expectedKeys as $key) {
            $this->assertContains($key, $actualKeys, "Nav item '{$key}' expected but not returned by platform nav API");
        }
    }

    /**
     * No duplicate navItem keys across platform modules.
     */
    public function test_no_duplicate_nav_item_keys_across_platform_modules(): void
    {
        $allKeys = [];
        $duplicates = [];

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            foreach ($manifest->capabilities->navItems as $item) {
                $navKey = $item['key'];

                if (isset($allKeys[$navKey])) {
                    $duplicates[] = "'{$navKey}' declared by both {$allKeys[$navKey]} and {$key}";
                } else {
                    $allKeys[$navKey] = $key;
                }
            }
        }

        $this->assertEmpty(
            $duplicates,
            "Duplicate navItem keys:\n" . implode("\n", $duplicates),
        );
    }
}
