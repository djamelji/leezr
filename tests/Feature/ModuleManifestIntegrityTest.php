<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates structural integrity of module manifests.
 *
 * Catches sortOrder collisions, missing permissions, orphan references.
 */
class ModuleManifestIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    public function test_no_sort_order_collisions_within_same_scope(): void
    {
        $byScope = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            $scope = $manifest->scope;
            $order = $manifest->sortOrder;

            if (isset($byScope[$scope][$order])) {
                $this->fail("sortOrder collision: {$byScope[$scope][$order]} and {$key} both use sortOrder={$order} in scope={$scope}");
            }

            $byScope[$scope][$order] = $key;
        }

        $this->assertTrue(true, 'No sortOrder collisions detected');
    }

    public function test_nav_item_permissions_reference_declared_permissions(): void
    {
        // Build permission catalog from all module manifests
        $allPermissions = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            foreach ($manifest->permissions as $perm) {
                $allPermissions[] = $perm['key'];
            }
        }

        $violations = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            foreach ($manifest->capabilities->navItems as $item) {
                $permission = $item['permission'] ?? null;

                if ($permission && !in_array($permission, $allPermissions, true)) {
                    $violations[] = "{$key}: navItem '{$item['key']}' references permission '{$permission}' which is not declared in any module";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "NavItem permissions reference undeclared permissions:\n" . implode("\n", $violations),
        );
    }

    public function test_requires_reference_valid_module_keys(): void
    {
        $allKeys = array_keys(ModuleRegistry::definitions());
        $violations = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            foreach ($manifest->requires as $requiredKey) {
                if (!in_array($requiredKey, $allKeys, true)) {
                    $violations[] = "{$key} requires '{$requiredKey}' which does not exist";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Module requires reference non-existent modules:\n" . implode("\n", $violations),
        );
    }

    public function test_module_keys_follow_naming_convention(): void
    {
        $violations = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            // Admin-scope modules must be prefixed with 'platform.' or 'payments.'
            if ($manifest->scope === 'admin') {
                if (!str_starts_with($key, 'platform.') && !str_starts_with($key, 'payments.')) {
                    $violations[] = "{$key}: admin-scope module should be prefixed with 'platform.' or 'payments.'";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Module key naming violations:\n" . implode("\n", $violations),
        );
    }
}
