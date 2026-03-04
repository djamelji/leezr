<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();
    }

    // ═══════════════════════════════════════════════════════
    // Auth
    // ═══════════════════════════════════════════════════════

    public function test_platform_nav_requires_auth(): void
    {
        $response = $this->getJson('/api/platform/nav');

        $response->assertStatus(401);
    }

    public function test_company_nav_requires_auth(): void
    {
        $response = $this->getJson('/api/nav');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════
    // Response structure
    // ═══════════════════════════════════════════════════════

    public function test_platform_nav_returns_grouped_structure(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('groups', $data);
        $this->assertIsArray($data['groups']);

        foreach ($data['groups'] as $group) {
            $this->assertArrayHasKey('key', $group);
            $this->assertArrayHasKey('titleKey', $group);
            $this->assertArrayHasKey('items', $group);
        }
    }

    public function test_company_nav_returns_grouped_structure(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('groups', $data);
        $this->assertIsArray($data['groups']);

        foreach ($data['groups'] as $group) {
            $this->assertArrayHasKey('key', $group);
            $this->assertArrayHasKey('titleKey', $group);
            $this->assertArrayHasKey('items', $group);
        }
    }

    public function test_nav_items_have_required_fields(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $response->assertOk();

        foreach ($response->json('groups') as $group) {
            foreach ($group['items'] as $item) {
                $this->assertArrayHasKey('key', $item);
                $this->assertArrayHasKey('title', $item);
                $this->assertArrayHasKey('to', $item);
                $this->assertArrayHasKey('icon', $item);
                $this->assertArrayHasKey('children', $item);
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Filtering
    // ═══════════════════════════════════════════════════════

    public function test_platform_nav_excludes_disabled_modules(): void
    {
        // Disable companies module
        \App\Core\Modules\PlatformModule::where('key', 'platform.companies')
            ->update(['is_enabled_globally' => false]);

        ModuleRegistry::clearCache();

        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        $this->assertNotContains('companies', $allKeys);
    }

    public function test_platform_nav_filters_by_user_permissions(): void
    {
        // Create user with only manage_modules permission
        $user = PlatformUser::create([
            'first_name' => 'Limited',
            'last_name' => 'Admin',
            'email' => 'limited-nav@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $role = PlatformRole::create(['name' => 'Module Admin', 'key' => 'module_admin_nav']);
        $perm = PlatformPermission::where('key', 'manage_modules')->first();

        if ($perm) {
            $role->permissions()->attach($perm);
        }

        $user->roles()->attach($role);

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        // Dashboard (no permission) — should be visible
        $this->assertContains('dashboard', $allKeys);

        // Modules (manage_modules) — should be visible
        $this->assertContains('modules', $allKeys);

        // Companies (manage_companies) — should NOT be visible
        $this->assertNotContains('companies', $allKeys);
    }

    public function test_platform_nav_super_admin_sees_all(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        // Super admin should see all visible + enabled module nav items
        foreach (ModuleRegistry::forScope('admin') as $manifest) {
            if ($manifest->visibility !== 'visible') {
                continue;
            }

            if (!ModuleGate::isEnabledGlobally($manifest->key)) {
                continue;
            }

            foreach ($manifest->capabilities->navItems as $item) {
                $this->assertContains($item['key'], $allKeys,
                    "Super admin should see '{$item['key']}' from '{$manifest->key}'");
            }
        }
    }

    public function test_company_nav_filters_by_activation(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        // Don't enable shipments module — it's addon, needs explicit activation
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        $this->assertNotContains('shipments', $allKeys,
            'Inactive addon module nav items should not appear');
    }

    public function test_company_nav_management_sees_structure_items(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        // Owner = management → sees structure items
        $this->assertContains('members', $allKeys, 'Management should see members');
        $this->assertContains('company-profile', $allKeys, 'Management should see company-profile');
    }

    public function test_company_nav_operational_hides_structure_items(): void
    {
        [$owner, $company] = $this->createCompanyOwner();

        // Create operational user
        $opUser = User::factory()->create();
        $opRole = CompanyRole::create([
            'company_id' => $company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        // Grant basic permissions
        $viewPerm = CompanyPermission::where('key', 'shipments.view')->first();

        if ($viewPerm) {
            $opRole->permissions()->attach($viewPerm);
        }

        $company->memberships()->create([
            'user_id' => $opUser->id,
            'role' => 'user',
            'company_role_id' => $opRole->id,
        ]);

        $response = $this->actingAs($opUser)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $allKeys = $this->extractItemKeysFromResponse($response);

        // Operational should NOT see structure items
        $this->assertNotContains('members', $allKeys, 'Operational should not see members');
        $this->assertNotContains('company-profile', $allKeys, 'Operational should not see company-profile');
    }

    // ═══════════════════════════════════════════════════════
    // Robustness
    // ═══════════════════════════════════════════════════════

    public function test_company_nav_returns_400_without_company_context(): void
    {
        $user = User::factory()->create();

        // No X-Company-Id header → middleware returns 400
        $response = $this->actingAs($user)
            ->getJson('/api/nav');

        $response->assertStatus(400);
    }

    public function test_company_nav_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Other Co', 'slug' => 'other-co', 'jobdomain_key' => 'logistique']);

        // User is not a member of this company
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Convergence — legacy matching
    // ═══════════════════════════════════════════════════════

    public function test_platform_nav_matches_legacy_auth_items(): void
    {
        // NavBuilder::flatForAdmin() should return the same keys
        // as the legacy platformModuleNavItems() method
        $flat = NavBuilder::flatForAdmin();
        $flatKeys = collect($flat)->pluck('key')->sort()->values()->all();

        $groups = NavBuilder::forAdmin();
        $groupKeys = collect($groups)
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($flatKeys, $groupKeys,
            'flatForAdmin() and forAdmin() should contain the same nav item keys');
    }

    public function test_company_nav_matches_legacy_module_items(): void
    {
        // Verify NavBuilder::forCompany returns items that cover
        // all company module nav items (core + enabled addons)
        $company = Company::create(['name' => 'Conv Co', 'slug' => 'conv-co', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        // Enable all company modules
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type !== 'core') {
                CompanyModule::create([
                    'company_id' => $company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }

        // Management + bypass permissions (null = owner) → see all items
        $groups = NavBuilder::forCompany($company, null, 'management');
        $navKeys = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $navKeys[] = $item['key'];
            }
        }

        // All active company module nav items (non-operationalOnly) should be present
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->visibility !== 'visible') {
                continue;
            }

            if (!ModuleGate::isActiveForScope($key, $company)) {
                continue;
            }

            foreach ($manifest->capabilities->navItems as $item) {
                // Skip operationalOnly items for management roleLevel
                if (!empty($item['operationalOnly'])) {
                    continue;
                }

                $this->assertContains($item['key'], $navKeys,
                    "Company nav should include '{$item['key']}' from module '{$key}'");
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function createSuperAdmin(): PlatformUser
    {
        $user = PlatformUser::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-nav@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $user->roles()->attach($superAdmin);

        return $user;
    }

    private function createCompanyOwner(): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Nav Co', 'slug' => 'nav-co-' . uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $company];
    }

    private function extractItemKeysFromResponse($response): array
    {
        $keys = [];

        foreach ($response->json('groups') ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $keys[] = $item['key'];

                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
