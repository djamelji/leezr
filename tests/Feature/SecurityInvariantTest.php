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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security invariant tests — guards against authorization regressions.
 *
 * These tests verify structural security properties that must NEVER break:
 *   - Dispatcher cannot access admin-only routes
 *   - Empty permissions ≠ bypass (unless proven owner/admin)
 *   - Owner is the only universal bypass
 *   - Non-member cannot access any company route
 *   - Suspended company blocks all access
 *   - Membership creation without CompanyRole is rejected
 */
class SecurityInvariantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $dispatcher;
    private User $driver;
    private User $unassigned;
    private User $nonMember;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Security Invariant Co',
            'slug' => 'sec-inv-co',
            'plan_key' => 'starter',
        ]);

        // Enable all modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // Administrative role (manager)
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $managerRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // Operational role (dispatcher, NOT administrative)
        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => false,
        ]);

        $opPerms = CompanyPermission::where('is_admin', false)
            ->whereIn('key', [
                'members.view', 'members.invite',
                'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign',
            ])
            ->pluck('id')->toArray();

        $dispatcherRole->permissions()->sync($opPerms);

        // Driver role (minimal)
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $driverPerms = CompanyPermission::where('is_admin', false)
            ->whereIn('key', ['members.view', 'shipments.view_own', 'shipments.manage_status'])
            ->pluck('id')->toArray();

        $driverRole->permissions()->sync($driverPerms);

        // ── Users ──
        $this->owner = User::factory()->create();
        $this->dispatcher = User::factory()->create();
        $this->driver = User::factory()->create();
        $this->unassigned = User::factory()->create();
        $this->nonMember = User::factory()->create();

        // ── Memberships ──
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->dispatcher->id,
            'role' => 'user',
            'company_role_id' => $dispatcherRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->driver->id,
            'role' => 'user',
            'company_role_id' => $driverRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->unassigned->id,
            'role' => 'user',
        ]);

        // nonMember is NOT a member of this company
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 1: Dispatcher cannot access admin routes
    // ═══════════════════════════════════════════════════════

    public function test_dispatcher_cannot_access_roles_endpoint(): void
    {
        $this->actAs($this->dispatcher)
            ->getJson('/api/company/roles')
            ->assertStatus(403);
    }

    public function test_dispatcher_cannot_create_role(): void
    {
        $this->actAs($this->dispatcher)
            ->postJson('/api/company/roles', [
                'key' => 'hacker',
                'name' => 'Hacker',
                'is_administrative' => true,
            ])
            ->assertStatus(403);
    }

    public function test_dispatcher_cannot_change_plan(): void
    {
        $this->actAs($this->dispatcher)
            ->putJson('/api/company/plan', ['plan_key' => 'business'])
            ->assertStatus(403);
    }

    public function test_dispatcher_cannot_manage_settings(): void
    {
        $this->actAs($this->dispatcher)
            ->putJson('/api/company', ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_dispatcher_cannot_manage_member_credentials(): void
    {
        $this->actAs($this->dispatcher)
            ->postJson("/api/company/members/{$this->driver->id}/reset-password")
            ->assertStatus(403);
    }

    public function test_dispatcher_cannot_update_members(): void
    {
        $this->actAs($this->dispatcher)
            ->putJson("/api/company/members/{$this->driver->id}", ['first_name' => 'Hacked'])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 2: Owner is the only universal bypass
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_access_roles(): void
    {
        $this->actAs($this->owner)
            ->getJson('/api/company/roles')
            ->assertOk();
    }

    public function test_owner_can_manage_settings(): void
    {
        $this->actAs($this->owner)
            ->putJson('/api/company', ['name' => 'Updated Name'])
            ->assertOk();
    }

    public function test_owner_can_view_shipments(): void
    {
        $this->actAs($this->owner)
            ->getJson('/api/shipments')
            ->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 3: Non-member cannot access any company route
    // ═══════════════════════════════════════════════════════

    public function test_non_member_blocked_on_nav(): void
    {
        $this->actingAs($this->nonMember)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav')
            ->assertStatus(403);
    }

    public function test_non_member_blocked_on_members(): void
    {
        $this->actingAs($this->nonMember)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/company/members')
            ->assertStatus(403);
    }

    public function test_non_member_blocked_on_shipments(): void
    {
        $this->actingAs($this->nonMember)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/shipments')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 4: Unassigned member (no CompanyRole) cannot escalate
    // ═══════════════════════════════════════════════════════

    public function test_unassigned_member_has_no_permissions(): void
    {
        $this->assertFalse(
            $this->unassigned->hasCompanyPermission($this->company, 'members.view'),
            'Unassigned member must not have any permissions'
        );
    }

    public function test_unassigned_member_cannot_manage_structure(): void
    {
        $this->assertFalse(
            CompanyAccess::can($this->unassigned, $this->company, 'manage-structure'),
            'Unassigned member must not be able to manage structure'
        );
    }

    public function test_unassigned_member_blocked_on_roles(): void
    {
        $this->actAs($this->unassigned)
            ->getJson('/api/company/roles')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 5: Suspended company blocks all access
    // ═══════════════════════════════════════════════════════

    public function test_suspended_company_blocks_owner(): void
    {
        $this->company->update(['status' => 'suspended']);

        $this->actAs($this->owner)
            ->getJson('/api/nav')
            ->assertStatus(403);
    }

    public function test_suspended_company_blocks_members(): void
    {
        $this->company->update(['status' => 'suspended']);

        $this->actAs($this->dispatcher)
            ->getJson('/api/company/members')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 6: Cross-company access is impossible
    // ═══════════════════════════════════════════════════════

    public function test_member_cannot_access_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-sec',
        ]);

        $this->actingAs($this->dispatcher)
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->getJson('/api/nav')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 7: Driver cannot access management routes
    // ═══════════════════════════════════════════════════════

    public function test_driver_cannot_create_shipments(): void
    {
        $this->actAs($this->driver)
            ->postJson('/api/shipments', ['reference' => 'SHP-HACK'])
            ->assertStatus(403);
    }

    public function test_driver_cannot_assign_shipments(): void
    {
        $this->actAs($this->driver)
            ->postJson('/api/shipments/1/assign', ['user_id' => $this->driver->id])
            ->assertStatus(403);
    }

    public function test_driver_cannot_invite_members(): void
    {
        $this->actAs($this->driver)
            ->postJson('/api/company/members', ['email' => 'hack@evil.com'])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 8: Module deactivation blocks access
    // ═══════════════════════════════════════════════════════

    public function test_disabled_module_blocks_even_owner(): void
    {
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $this->actAs($this->owner)
            ->getJson('/api/shipments')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // INVARIANT 9: Membership creation requires CompanyRole
    // ═══════════════════════════════════════════════════════

    public function test_membership_creation_without_company_role_rejected(): void
    {
        $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'newuser@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_role_id');
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }
}
