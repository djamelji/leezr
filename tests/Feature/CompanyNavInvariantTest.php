<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant: every company-scope navItem with surface=structure
 * must declare a permission. Otherwise, switching a role to
 * management silently exposes the item with no guard.
 *
 * Also validates that the permission gate actually blocks
 * management users who lack the required permission.
 */
class CompanyNavInvariantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Invariant Co',
            'slug' => 'invariant-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        $this->owner = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // Structural invariant — no structure item without permission
    // ───────────────────────────────────────────────────────────

    public function test_all_company_structure_nav_items_have_permission(): void
    {
        $manifests = ModuleRegistry::forScope('company');

        $violations = [];

        foreach ($manifests as $key => $manifest) {
            foreach ($manifest->capabilities->navItems as $data) {
                $item = NavItem::fromManifestArray($data);

                if ($item->surface === 'structure' && $item->permission === null) {
                    $violations[] = "{$key} → navItem '{$item->key}' (surface=structure, permission=null)";
                }
            }
        }

        $this->assertEmpty($violations,
            "Structure navItems must have a permission to prevent leakage on management roles:\n"
            .implode("\n", $violations));
    }

    // ───────────────────────────────────────────────────────────
    // Regression: management WITHOUT members.manage → no Roles
    // ───────────────────────────────────────────────────────────

    public function test_management_without_roles_view_does_not_see_roles(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited_mgmt',
            'name' => 'Limited Management',
            'is_administrative' => true,
        ]);

        // Give settings.view + shipments.view, but NOT roles.view
        $perms = CompanyPermission::whereIn('key', ['settings.view', 'shipments.view'])
            ->pluck('id')->toArray();
        $role->permissions()->sync($perms);

        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $keys = $this->navKeysFor($user);

        $this->assertNotContains('company-roles', $keys,
            'Management role without roles.view must NOT see Roles nav item');
        $this->assertContains('settings', $keys,
            'Management role with settings.view should see Settings');
    }

    // ───────────────────────────────────────────────────────────
    // Regression: management WITHOUT jobdomain.view → no Industry
    // ───────────────────────────────────────────────────────────

    public function test_management_with_selective_permissions_sees_correct_nav(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited_mgmt2',
            'name' => 'Limited Management 2',
            'is_administrative' => true,
        ]);

        // Give roles.view + shipments.view
        $perms = CompanyPermission::whereIn('key', ['roles.view', 'shipments.view'])
            ->pluck('id')->toArray();
        $role->permissions()->sync($perms);

        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $keys = $this->navKeysFor($user);

        $this->assertContains('company-roles', $keys,
            'Management role with roles.view should see Roles');
    }

    // ───────────────────────────────────────────────────────────
    // Owner still sees everything (bypass unchanged)
    // ───────────────────────────────────────────────────────────

    public function test_owner_still_sees_all_structure_items(): void
    {
        $keys = $this->navKeysFor($this->owner);

        $this->assertContains('company-roles', $keys, 'Owner should see Roles');
        $this->assertContains('members', $keys, 'Owner should see Members');
        $this->assertContains('settings', $keys, 'Owner should see Settings');
    }

    // ───────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────

    private function navKeysFor(User $user): array
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav');

        $response->assertOk();

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
