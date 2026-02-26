<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Company\Security\CompanyAccess;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization contract tests — anti-regression for RBAC bypass bugs.
 *
 * Contract: "is_administrative" grants management surface access,
 *           but NEVER bypasses permission checks.
 *           Only "owner" bypasses permissions.
 *
 * These tests reproduce the exact bug where an administrative CompanyRole
 * without billing permission could still see billing nav items, and prevent
 * it from ever returning.
 */
class AuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $adminWithBilling;
    private User $adminWithoutBilling;
    private User $operational;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Contract Co',
            'slug' => 'contract-co',
            'plan_key' => 'starter',
        ]);

        // Enable all company modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // ── Roles ──

        // Administrative role WITH billing permission (full admin)
        $fullAdminRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'full_admin',
            'name' => 'Full Admin',
            'is_administrative' => true,
        ]);

        $fullAdminRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // Administrative role WITHOUT billing permission
        $limitedAdminRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited_admin',
            'name' => 'Limited Admin',
            'is_administrative' => true,
        ]);

        // Give all permissions EXCEPT billing
        $nonBillingPerms = CompanyPermission::where('key', 'NOT LIKE', 'billing.%')
            ->pluck('id')->toArray();

        $limitedAdminRole->syncPermissionsSafe($nonBillingPerms);

        // Operational role (not administrative)
        $operationalRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => false,
        ]);

        $opPerms = CompanyPermission::where('is_admin', false)
            ->whereIn('key', ['members.view', 'members.invite', 'shipments.view'])
            ->pluck('id')->toArray();

        $operationalRole->permissions()->sync($opPerms);

        // ── Users + Memberships ──
        $this->owner = User::factory()->create();
        $this->adminWithBilling = User::factory()->create();
        $this->adminWithoutBilling = User::factory()->create();
        $this->operational = User::factory()->create();

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->adminWithBilling->id,
            'role' => 'user',
            'company_role_id' => $fullAdminRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->adminWithoutBilling->id,
            'role' => 'user',
            'company_role_id' => $limitedAdminRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->operational->id,
            'role' => 'user',
            'company_role_id' => $operationalRole->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 1: Administrative ≠ permission bypass
    //   (THE EXACT BUG BEING FIXED)
    // ═══════════════════════════════════════════════════════

    public function test_administrative_without_billing_permission_cannot_see_billing_nav(): void
    {
        $response = $this->actAs($this->adminWithoutBilling)
            ->getJson('/api/nav');

        $response->assertOk();

        $navKeys = $this->extractNavKeys($response->json('groups'));

        $this->assertNotContains('plan', $navKeys,
            'Administrative role WITHOUT billing.manage must NOT see Plan nav item');

        $this->assertNotContains('billing', $navKeys,
            'Administrative role WITHOUT billing.manage must NOT see Billing nav item');
    }

    public function test_administrative_with_billing_permission_can_see_billing_nav(): void
    {
        $response = $this->actAs($this->adminWithBilling)
            ->getJson('/api/nav');

        $response->assertOk();

        $navKeys = $this->extractNavKeys($response->json('groups'));

        $this->assertContains('plan', $navKeys,
            'Administrative role WITH billing.manage must see Plan nav item');

        $this->assertContains('billing', $navKeys,
            'Administrative role WITH billing.manage must see Billing nav item');
    }

    public function test_operational_cannot_change_plan(): void
    {
        // Plan change is gated by manage-structure, not billing.manage.
        // Operational users cannot manage structure.
        $this->actAs($this->operational)
            ->putJson('/api/company/plan', ['plan_key' => 'business'])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 2: Owner is the only universal bypass
    // ═══════════════════════════════════════════════════════

    public function test_owner_sees_billing_nav_without_company_role(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/nav');

        $response->assertOk();

        $navKeys = $this->extractNavKeys($response->json('groups'));

        $this->assertContains('plan', $navKeys,
            'Owner must see Plan nav item even without CompanyRole');

        $this->assertContains('billing', $navKeys,
            'Owner must see Billing nav item even without CompanyRole');
    }

    public function test_owner_can_change_plan(): void
    {
        $this->actAs($this->owner)
            ->putJson('/api/company/plan', ['plan_key' => 'business'])
            ->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 3: Empty permissions ≠ allow all
    // ═══════════════════════════════════════════════════════

    public function test_empty_permissions_array_does_not_bypass_nav_filter(): void
    {
        // NavBuilder with [] = user has no permissions
        // Should NOT show items that require permissions
        $groups = NavBuilder::forCompany($this->company, [], 'management');
        $navKeys = $this->extractNavKeys($groups);

        $this->assertNotContains('plan', $navKeys,
            'Empty permissions array must NOT show permission-gated items');

        $this->assertNotContains('billing', $navKeys,
            'Empty permissions array must NOT show permission-gated items');
    }

    public function test_null_permissions_bypasses_nav_filter(): void
    {
        // NavBuilder with null = owner bypass
        // Should show all items including permission-gated ones
        $groups = NavBuilder::forCompany($this->company, null, 'management');
        $navKeys = $this->extractNavKeys($groups);

        $this->assertContains('plan', $navKeys,
            'null permissions (owner) must show all items');

        $this->assertContains('billing', $navKeys,
            'null permissions (owner) must show all items');
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 4: Administrative sees management surface
    //   (surface access preserved — not broken by fix)
    // ═══════════════════════════════════════════════════════

    public function test_administrative_sees_management_surface_items_with_permission(): void
    {
        $response = $this->actAs($this->adminWithoutBilling)
            ->getJson('/api/nav');

        $response->assertOk();

        $navKeys = $this->extractNavKeys($response->json('groups'));

        // Members nav item requires members.view — limited admin has it
        $this->assertContains('members', $navKeys,
            'Administrative role with members.view must see Members nav item');
    }

    public function test_administrative_gets_management_role_level(): void
    {
        $membership = $this->adminWithoutBilling->membershipFor($this->company);
        $this->assertTrue($membership->isAdmin(),
            'Administrative CompanyRole must return isAdmin()=true');
    }

    public function test_operational_does_not_see_management_surface_items(): void
    {
        $response = $this->actAs($this->operational)
            ->getJson('/api/nav');

        $response->assertOk();

        $navKeys = $this->extractNavKeys($response->json('groups'));

        // Settings is a structure item — operational cannot see it
        $this->assertNotContains('settings', $navKeys,
            'Operational role must NOT see structure surface items');
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 5: DB/runtime invariant — non-owner requires CompanyRole
    // ═══════════════════════════════════════════════════════

    public function test_non_owner_without_company_role_blocked_by_middleware(): void
    {
        // Create a membership without CompanyRole (bypassing validation)
        $orphanUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $orphanUser->id,
            'role' => 'user',
        ]);

        $this->actingAs($orphanUser)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // CONTRACT 6: CompanyAccess gate — administrative does NOT bypass permissions
    // ═══════════════════════════════════════════════════════

    public function test_administrative_cannot_bypass_use_permission_ability(): void
    {
        // billing.manage is not in limited admin's permissions
        $this->assertFalse(
            $this->adminWithoutBilling->hasCompanyPermission($this->company, 'billing.manage'),
            'Administrative role without billing.manage must NOT have the permission'
        );
    }

    public function test_administrative_can_access_manage_structure_ability(): void
    {
        $this->assertTrue(
            CompanyAccess::can($this->adminWithoutBilling, $this->company, 'manage-structure'),
            'Administrative role must be able to manage-structure (surface access)'
        );
    }

    public function test_operational_cannot_access_manage_structure_ability(): void
    {
        $this->assertFalse(
            CompanyAccess::can($this->operational, $this->company, 'manage-structure'),
            'Operational role must NOT be able to manage-structure'
        );
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function extractNavKeys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];

                if (!empty($item['children'])) {
                    foreach ($item['children'] as $child) {
                        $keys[] = $child['key'];
                    }
                }
            }
        }

        return $keys;
    }
}
