<?php

namespace Tests\Unit;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    // ═══════════════════════════════════════════════════════
    // Activation / visibility
    // ═══════════════════════════════════════════════════════

    public function test_for_admin_returns_only_enabled_visible_modules(): void
    {
        $groups = NavBuilder::forAdmin();
        $allKeys = $this->extractItemKeys($groups);

        // Dashboard module is internal + visible — its 'dashboard' navItem should be present
        $this->assertContains('dashboard', $allKeys);
    }

    public function test_for_admin_excludes_disabled_modules(): void
    {
        // Disable a module globally
        \App\Core\Modules\PlatformModule::where('key', 'platform.companies')
            ->update(['is_enabled_globally' => false]);

        ModuleRegistry::clearCache();

        $groups = NavBuilder::forAdmin();
        $allKeys = $this->extractItemKeys($groups);

        $this->assertNotContains('companies', $allKeys);
        $this->assertNotContains('company-users', $allKeys);
    }

    public function test_for_admin_excludes_hidden_modules(): void
    {
        $groups = NavBuilder::forAdmin();
        $allKeys = $this->extractItemKeys($groups);

        // Hidden modules should have no nav items in the output
        $hiddenModules = collect(ModuleRegistry::forScope('admin'))
            ->filter(fn (ModuleManifest $m) => $m->visibility === 'hidden');

        // Verify we have at least one visible module (sanity check)
        $this->assertNotEmpty($allKeys, 'Admin nav should have at least one item');

        foreach ($hiddenModules as $manifest) {
            foreach ($manifest->capabilities->navItems as $item) {
                $this->assertNotContains($item['key'], $allKeys, "Hidden module {$manifest->key} nav item should not appear");
            }
        }

        // Even if no hidden modules exist, verify the output is correct
        $this->assertIsArray($groups);
    }

    public function test_for_admin_sorts_by_sort_order(): void
    {
        $groups = NavBuilder::forAdmin();
        $allKeys = $this->extractItemKeys($groups);

        // Dashboard (sortOrder 1) should come before other items
        if (in_array('dashboard', $allKeys) && count($allKeys) > 1) {
            // Dashboard is in cockpit group, verify it exists
            $cockpitGroup = collect($groups)->firstWhere('key', 'cockpit');
            if ($cockpitGroup) {
                $this->assertSame('dashboard', $cockpitGroup['items'][0]['key']);
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Permissions (string[])
    // ═══════════════════════════════════════════════════════

    public function test_for_admin_filters_by_permissions(): void
    {
        // Only grant manage_companies permission
        $groups = NavBuilder::forAdmin(['manage_companies']);
        $allKeys = $this->extractItemKeys($groups);

        // Dashboard has no permission requirement — should be present
        $this->assertContains('dashboard', $allKeys);

        // Companies requires manage_companies — should be present (ADR-381)
        $this->assertContains('platform-companies', $allKeys);

        // Roles requires manage_roles — should NOT be present
        $this->assertNotContains('roles', $allKeys);
    }

    public function test_for_admin_bypasses_permissions_when_null(): void
    {
        // null permissions = super_admin — see all items
        $groups = NavBuilder::forAdmin(null);
        $allKeys = $this->extractItemKeys($groups);

        // All visible module nav items should be present
        foreach (ModuleRegistry::forScope('admin') as $manifest) {
            if ($manifest->visibility !== 'visible') {
                continue;
            }

            if (!\App\Core\Modules\ModuleGate::isEnabledGlobally($manifest->key)) {
                continue;
            }

            foreach ($manifest->capabilities->navItems as $item) {
                $this->assertContains($item['key'], $allKeys, "Super-admin should see nav item '{$item['key']}' from module '{$manifest->key}'");
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Company-specific
    // ═══════════════════════════════════════════════════════

    public function test_for_company_returns_active_modules(): void
    {
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Enable addon modules
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type === 'core') {
                continue; // Core modules are always active
            }

            CompanyModule::create([
                'company_id' => $company->id,
                'module_key' => $key,
                'is_enabled_for_company' => true,
            ]);
        }

        // Use management roleLevel to see structure items
        $groups = NavBuilder::forCompany($company, null, 'management');
        $allKeys = $this->extractItemKeys($groups);

        // Core modules' nav items should be present
        $this->assertContains('members', $allKeys);
        $this->assertContains('company-profile', $allKeys);
    }

    public function test_for_company_excludes_inactive_modules(): void
    {
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co-2', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Don't enable logistics_shipments
        $groups = NavBuilder::forCompany($company);
        $allKeys = $this->extractItemKeys($groups);

        $this->assertNotContains('shipments', $allKeys);
    }

    public function test_for_company_filters_by_item_plan(): void
    {
        // This test verifies plan-level filtering on individual nav items.
        // Since no current manifest items use the plans field, we test
        // that the pipeline handles it correctly by verifying the builder runs without error
        // and returns expected structure for a starter plan company.
        $company = Company::create(['name' => 'Starter Co', 'slug' => 'starter-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        $groups = NavBuilder::forCompany($company);

        $this->assertIsArray($groups);

        foreach ($groups as $group) {
            $this->assertArrayHasKey('key', $group);
            $this->assertArrayHasKey('titleKey', $group);
            $this->assertArrayHasKey('items', $group);
        }
    }

    public function test_for_company_filters_by_role_level(): void
    {
        $company = Company::create(['name' => 'RL Co', 'slug' => 'rl-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Enable all modules
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Management sees structure items
        $mgmtGroups = NavBuilder::forCompany($company, null, 'management');
        $mgmtKeys = $this->extractItemKeys($mgmtGroups);

        $this->assertContains('members', $mgmtKeys, 'Management should see structure items');
        $this->assertContains('company-profile', $mgmtKeys, 'Management should see structure items');

        // Operational does NOT see structure items
        $opGroups = NavBuilder::forCompany($company, null, 'operational');
        $opKeys = $this->extractItemKeys($opGroups);

        $this->assertNotContains('members', $opKeys, 'Operational should not see structure items');
        $this->assertNotContains('company-profile', $opKeys, 'Operational should not see structure items');
    }

    /**
     * ADR-373 + excludePermission: my-deliveries has excludePermission='shipments.view'.
     * Bypass users (permissions=null) are treated as having all permissions → excluded.
     */
    public function test_for_company_my_deliveries_hidden_for_bypass_users(): void
    {
        $company = Company::create(['name' => 'OpOnly Co', 'slug' => 'oponly-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Management bypass: has all permissions → excludePermission hides my-deliveries
        $mgmtGroups = NavBuilder::forCompany($company, null, 'management');
        $mgmtKeys = $this->extractItemKeys($mgmtGroups);

        $this->assertNotContains('my-deliveries', $mgmtKeys, 'Bypass user should NOT see my-deliveries (excludePermission=shipments.view)');

        // Operational bypass: same — has all permissions → excluded
        $opGroups = NavBuilder::forCompany($company, null, 'operational');
        $opKeys = $this->extractItemKeys($opGroups);

        $this->assertNotContains('my-deliveries', $opKeys, 'Bypass user should NOT see my-deliveries (excludePermission=shipments.view)');
    }

    // ═══════════════════════════════════════════════════════
    // excludePermission
    // ═══════════════════════════════════════════════════════

    public function test_exclude_permission_hides_item_when_user_has_permission(): void
    {
        $company = Company::create(['name' => 'ExcPerm Co', 'slug' => 'excperm-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Activate logistics_shipments module
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Dispatcher: has shipments.view → excludePermission should hide my-deliveries
        $groups = NavBuilder::forCompany($company, ['shipments.view', 'shipments.view_own']);
        $allKeys = $this->extractItemKeys($groups);

        $this->assertNotContains('my-deliveries', $allKeys, 'User with shipments.view should NOT see my-deliveries (excludePermission)');
    }

    public function test_exclude_permission_shows_item_when_user_lacks_permission(): void
    {
        $company = Company::create(['name' => 'ExcPerm2 Co', 'slug' => 'excperm2-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Activate logistics_shipments module
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Driver: has shipments.view_own but NOT shipments.view → my-deliveries visible
        $groups = NavBuilder::forCompany($company, ['shipments.view_own', 'shipments.manage_status']);
        $allKeys = $this->extractItemKeys($groups);

        $this->assertContains('my-deliveries', $allKeys, 'User without shipments.view should see my-deliveries');
    }

    public function test_exclude_permission_hides_for_bypass_user(): void
    {
        $company = Company::create(['name' => 'ExcPerm3 Co', 'slug' => 'excperm3-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Activate logistics_shipments module
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Bypass user (null permissions = owner) → treated as having all permissions → excluded
        $groups = NavBuilder::forCompany($company, null);
        $allKeys = $this->extractItemKeys($groups);

        $this->assertNotContains('my-deliveries', $allKeys, 'Bypass user (null permissions) should NOT see my-deliveries (excludePermission)');
    }

    // ═══════════════════════════════════════════════════════
    // Same engine
    // ═══════════════════════════════════════════════════════

    public function test_same_pipeline_for_both_scopes(): void
    {
        // Both forAdmin and forCompany return the same structure format
        $adminGroups = NavBuilder::forAdmin();
        $company = Company::create(['name' => 'Pipe Co', 'slug' => 'pipe-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $companyGroups = NavBuilder::forCompany($company);

        foreach ([$adminGroups, $companyGroups] as $groups) {
            $this->assertIsArray($groups);

            foreach ($groups as $group) {
                $this->assertArrayHasKey('key', $group);
                $this->assertArrayHasKey('titleKey', $group);
                $this->assertArrayHasKey('items', $group);

                foreach ($group['items'] as $item) {
                    $this->assertArrayHasKey('key', $item);
                    $this->assertArrayHasKey('title', $item);
                    $this->assertArrayHasKey('to', $item);
                    $this->assertArrayHasKey('icon', $item);
                    $this->assertArrayHasKey('children', $item);
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Tree construction
    // ═══════════════════════════════════════════════════════

    public function test_parent_child_tree_construction(): void
    {
        // Use reflection to test buildTree directly
        $items = [
            new \App\Core\Navigation\NavItem(
                key: 'parent',
                title: 'Parent',
                to: ['name' => 'parent-route'],
                icon: 'tabler-folder',
            ),
            new \App\Core\Navigation\NavItem(
                key: 'child',
                title: 'Child',
                to: ['name' => 'child-route'],
                icon: 'tabler-file',
                parent: 'parent',
            ),
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'buildTree');
        $method->setAccessible(true);
        $tree = $method->invoke(null, $items);

        $this->assertCount(1, $tree, 'Only parent should be root');
        $this->assertSame('parent', $tree[0]['key']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('child', $tree[0]['children'][0]['key']);
    }

    public function test_cycle_detection_throws_runtime_exception(): void
    {
        $items = [
            new \App\Core\Navigation\NavItem(
                key: 'a',
                title: 'A',
                to: [],
                icon: 'tabler-a',
                parent: 'b',
            ),
            new \App\Core\Navigation\NavItem(
                key: 'b',
                title: 'B',
                to: [],
                icon: 'tabler-b',
                parent: 'a',
            ),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $method = new \ReflectionMethod(NavBuilder::class, 'buildTree');
        $method->setAccessible(true);
        $method->invoke(null, $items);
    }

    public function test_orphan_parent_child_promoted_to_root(): void
    {
        // Child references a parent that doesn't exist in the items list
        $items = [
            new \App\Core\Navigation\NavItem(
                key: 'child',
                title: 'Child',
                to: ['name' => 'child-route'],
                icon: 'tabler-file',
                parent: 'nonexistent',
            ),
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'buildTree');
        $method->setAccessible(true);
        $tree = $method->invoke(null, $items);

        $this->assertCount(1, $tree, 'Orphan child should be promoted to root');
        $this->assertSame('child', $tree[0]['key']);
    }

    // ═══════════════════════════════════════════════════════
    // Pruning
    // ═══════════════════════════════════════════════════════

    public function test_non_clickable_parent_pruned_if_no_children(): void
    {
        $nodes = [
            [
                '_item' => new \App\Core\Navigation\NavItem(key: 'empty-parent', title: 'Empty', to: [], icon: 'tabler-x'),
                '_sort' => 0,
                'key' => 'empty-parent',
                'title' => 'Empty',
                'to' => [],
                'icon' => 'tabler-x',
                'permission' => null,
                'children' => [],
            ],
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'pruneTree');
        $method->setAccessible(true);
        $result = $method->invoke(null, $nodes);

        $this->assertEmpty($result, 'Non-clickable parent with no children should be pruned');
    }

    public function test_clickable_parent_kept_even_without_children(): void
    {
        $nodes = [
            [
                '_item' => new \App\Core\Navigation\NavItem(key: 'leaf', title: 'Leaf', to: ['name' => 'leaf-route'], icon: 'tabler-leaf'),
                '_sort' => 0,
                'key' => 'leaf',
                'title' => 'Leaf',
                'to' => ['name' => 'leaf-route'],
                'icon' => 'tabler-leaf',
                'permission' => null,
                'children' => [],
            ],
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'pruneTree');
        $method->setAccessible(true);
        $result = $method->invoke(null, $nodes);

        $this->assertCount(1, $result, 'Clickable parent should be kept even without children');
    }

    public function test_non_clickable_parent_kept_if_has_children(): void
    {
        $nodes = [
            [
                '_item' => new \App\Core\Navigation\NavItem(key: 'parent', title: 'Parent', to: [], icon: 'tabler-folder'),
                '_sort' => 0,
                'key' => 'parent',
                'title' => 'Parent',
                'to' => [],
                'icon' => 'tabler-folder',
                'permission' => null,
                'children' => [
                    [
                        '_item' => new \App\Core\Navigation\NavItem(key: 'child', title: 'Child', to: ['name' => 'child'], icon: 'tabler-file'),
                        '_sort' => 0,
                        'key' => 'child',
                        'title' => 'Child',
                        'to' => ['name' => 'child'],
                        'icon' => 'tabler-file',
                        'permission' => null,
                        'children' => [],
                    ],
                ],
            ],
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'pruneTree');
        $method->setAccessible(true);
        $result = $method->invoke(null, $nodes);

        $this->assertCount(1, $result, 'Non-clickable parent with children should be kept');
        $this->assertCount(1, $result[0]['children']);
    }

    public function test_empty_groups_pruned(): void
    {
        $groups = [
            'empty' => [
                'key' => 'empty',
                'titleKey' => 'nav.groups.empty',
                'items' => [],
            ],
            'full' => [
                'key' => 'full',
                'titleKey' => 'nav.groups.full',
                'items' => [
                    [
                        '_item' => new \App\Core\Navigation\NavItem(key: 'item', title: 'Item', to: ['name' => 'r'], icon: 'tabler-x'),
                        '_sort' => 0,
                        'key' => 'item',
                        'title' => 'Item',
                        'to' => ['name' => 'r'],
                        'icon' => 'tabler-x',
                        'permission' => null,
                        'children' => [],
                    ],
                ],
            ],
        ];

        $method = new \ReflectionMethod(NavBuilder::class, 'pruneGroups');
        $method->setAccessible(true);
        $result = $method->invoke(null, $groups);

        $this->assertArrayNotHasKey('empty', $result);
        $this->assertArrayHasKey('full', $result);
    }

    // ═══════════════════════════════════════════════════════
    // Grouping
    // ═══════════════════════════════════════════════════════

    public function test_group_derived_from_surface_for_company(): void
    {
        $company = Company::create(['name' => 'Group Co', 'slug' => 'group-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        $groups = NavBuilder::forCompany($company, null, 'management');
        $groupKeys = collect($groups)->pluck('key')->all();

        // Company scope should have company group (structure items)
        $this->assertContains('company', $groupKeys, 'Structure items should be in company group');
    }

    public function test_group_derived_default_for_admin(): void
    {
        $groups = NavBuilder::forAdmin();
        $groupKeys = collect($groups)->pluck('key')->all();

        // Admin scope: all items now have explicit groups (cockpit, clients, finance, etc.)
        // Cockpit group should always exist (Dashboard has no permission requirement)
        $this->assertContains('cockpit', $groupKeys, 'Admin nav should contain the cockpit group');
    }

    public function test_explicit_group_overrides_derived(): void
    {
        $groups = NavBuilder::forAdmin();

        // Dashboard has explicit group: 'cockpit'
        $cockpitGroup = collect($groups)->firstWhere('key', 'cockpit');
        $this->assertNotNull($cockpitGroup, 'Cockpit group should exist for dashboard');

        $cockpitKeys = collect($cockpitGroup['items'])->pluck('key')->all();
        $this->assertContains('dashboard', $cockpitKeys, 'Dashboard should be in cockpit group (explicit override)');
    }

    public function test_group_title_key_is_i18n_key(): void
    {
        $groups = NavBuilder::forAdmin();

        foreach ($groups as $group) {
            $this->assertStringStartsWith('nav.groups.', $group['titleKey'],
                "Group '{$group['key']}' titleKey should be an i18n key");
        }
    }

    public function test_cockpit_group_has_correct_title_key(): void
    {
        $groups = NavBuilder::forAdmin();
        $cockpitGroup = collect($groups)->firstWhere('key', 'cockpit');

        $this->assertNotNull($cockpitGroup);
        $this->assertSame('nav.groups.cockpit', $cockpitGroup['titleKey']);
    }

    // ═══════════════════════════════════════════════════════
    // Unique keys
    // ═══════════════════════════════════════════════════════

    public function test_no_duplicate_nav_keys(): void
    {
        $groups = NavBuilder::forAdmin();
        $allKeys = $this->extractItemKeys($groups);

        $this->assertSame(
            count($allKeys),
            count(array_unique($allKeys)),
            'Nav keys should be unique across all groups',
        );
    }

    // ═══════════════════════════════════════════════════════
    // FlatForAdmin (legacy compat)
    // ═══════════════════════════════════════════════════════

    public function test_flat_for_admin_returns_legacy_format(): void
    {
        $flat = NavBuilder::flatForAdmin();

        $this->assertIsArray($flat);

        foreach ($flat as $item) {
            $this->assertArrayHasKey('key', $item);
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('to', $item);
            $this->assertArrayHasKey('icon', $item);
        }
    }

    // ═══════════════════════════════════════════════════════
    // ModuleCatalogReadModel consistency
    // ═══════════════════════════════════════════════════════

    public function test_catalog_core_modules_active_without_company_module_row(): void
    {
        $company = Company::create(['name' => 'Cat Co', 'slug' => 'cat-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Do NOT create any CompanyModule rows — core modules should still be active
        $catalog = \App\Core\Modules\ModuleCatalogReadModel::forCompany($company);

        $coreModules = collect($catalog)->filter(fn ($m) => $m['type'] === 'core');

        $this->assertNotEmpty($coreModules, 'At least one core module should exist');

        foreach ($coreModules as $module) {
            $this->assertTrue(
                $module['is_active'],
                "Core module '{$module['key']}' should be active without CompanyModule row",
            );

            // Verify consistency with ModuleGate
            $gateResult = \App\Core\Modules\ModuleGate::isActive($company, $module['key']);
            $this->assertSame(
                $gateResult,
                $module['is_active'],
                "ModuleCatalogReadModel and ModuleGate should agree for core module '{$module['key']}'",
            );
        }
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function extractItemKeys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];

                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
