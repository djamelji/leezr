<?php

namespace Tests\Feature;

use App\Core\Navigation\NavBuilder;
use App\Core\Navigation\NavGroupRegistry;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-596 (LOT 2) — NavGroupRegistry is the single source of truth for navigation groups.
 * Every group a module assigns must be registered for its scope, every registered group must
 * have an i18n heading, several modules may share a group, and ordering is registry-driven.
 */
class NavGroupRegistryContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function resolveGroup(array $navItem, string $scope): string
    {
        if (isset($navItem['group'])) {
            return $navItem['group'];
        }
        if ($scope === 'admin') {
            return 'management';
        }

        return ($navItem['surface'] ?? null) === 'operations' ? 'operations' : 'company';
    }

    public function test_every_manifest_group_is_registered_for_its_scope(): void
    {
        foreach (['admin', 'company'] as $scope) {
            foreach (ModuleRegistry::forScope($scope) as $manifest) {
                /** @var ModuleManifest $manifest */
                foreach ($manifest->capabilities->navItems as $navItem) {
                    $group = $this->resolveGroup($navItem, $scope);
                    $this->assertTrue(
                        NavGroupRegistry::has($scope, $group),
                        "Group '{$group}' used by module '{$manifest->key}' (scope {$scope}) is NOT registered in NavGroupRegistry"
                    );
                }
            }
        }
    }

    public function test_every_registered_group_has_i18n_in_both_locales(): void
    {
        $en = json_decode(file_get_contents(resource_path('js/plugins/i18n/locales/en.json')), true)['nav']['groups'];
        $fr = json_decode(file_get_contents(resource_path('js/plugins/i18n/locales/fr.json')), true)['nav']['groups'];

        foreach (['admin', 'company'] as $scope) {
            foreach (NavGroupRegistry::keys($scope) as $key) {
                $this->assertArrayHasKey($key, $en, "nav.groups.{$key} missing in en.json");
                $this->assertArrayHasKey($key, $fr, "nav.groups.{$key} missing in fr.json");
            }
        }
    }

    public function test_multiple_modules_contribute_to_one_shared_group(): void
    {
        // The 'clients' admin group is fed by Companies, Modules and Support modules — proof that a
        // group is a shared keyed registry, not owned by a single module.
        $groups = collect(NavBuilder::forAdmin(null))->keyBy('key');
        $this->assertTrue($groups->has('clients'), 'clients group present');
        $this->assertGreaterThanOrEqual(
            2,
            count($groups['clients']['items']),
            'the shared clients group holds items contributed by several modules'
        );
    }

    public function test_group_order_is_registry_driven_and_deterministic(): void
    {
        $keys = collect(NavBuilder::forAdmin(null))->pluck('key')->all();
        $pos = array_flip($keys);

        // Rendered groups follow the registry order (cockpit < clients < finance < operations < administration).
        $this->assertTrue($pos['cockpit'] < $pos['clients'], 'cockpit before clients');
        $this->assertTrue($pos['clients'] < $pos['finance'], 'clients before finance');
        $this->assertTrue($pos['finance'] < $pos['operations'], 'finance before operations');
        $this->assertTrue($pos['operations'] < $pos['administration'], 'operations before administration');
    }

    public function test_dead_product_group_is_not_registered(): void
    {
        $this->assertFalse(NavGroupRegistry::has('admin', 'product'), 'dead product group removed from ordering');
    }
}
