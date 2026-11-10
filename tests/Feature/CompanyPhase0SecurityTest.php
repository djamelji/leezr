<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Company\RBAC\MembershipSecurityPolicy;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Employees\Services\EmployeeAccessService;
use App\Core\Workforce\Services\LinkedIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-752 (Consolidation Company — Phase 0). Autorité serveur UNIQUE d'attribution de rôle +
 * cycle de vie sécurisé + prévention des divergences d'identité pour les personnes reliées.
 */
class CompanyPhase0SecurityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private User $admin;
    private User $inviter;   // NON-admin, détient members.invite
    private User $member;
    private CompanyRole $adminRole;
    private CompanyRole $viewerRole;
    private CompanyRole $inviterRole;
    private $adminMembership;
    private $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->inviter = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Phase0 Co', 'slug' => 'phase0-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $this->adminRole = $this->setUpCompanyRbac($this->company); // is_administrative=true, toutes perms

        $this->viewerRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'viewer', 'name' => 'Viewer', 'is_administrative' => false]);
        $this->viewerRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['members.view'])->pluck('id')->all());

        // Rôle NON-admin détenant members.invite (is_admin=false) → peut donner un accès collaborateur.
        $this->inviterRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'inviter', 'name' => 'Inviter', 'is_administrative' => false]);
        $this->inviterRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['members.view', 'members.invite', 'workforce.view'])->pluck('id')->all());

        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->adminMembership = $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'user', 'company_role_id' => $this->adminRole->id]);
        $this->company->memberships()->create(['user_id' => $this->inviter->id, 'role' => 'user', 'company_role_id' => $this->inviterRole->id]);
        $this->memberMembership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => 'user', 'company_role_id' => $this->viewerRole->id]);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(),
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => uniqid().'@co.test', 'status' => 'active',
            'hire_date' => '2026-01-01',
        ], $attrs));
    }

    // ── Attribution de rôle (autorité unique) ──

    public function test_owner_role_change_is_forbidden_403(): void
    {
        $ownerM = $this->company->memberships()->where('user_id', $this->owner->id)->first();
        $this->as($this->admin)->putJson("/api/company/members/{$ownerM->id}", ['company_role_id' => $this->viewerRole->id])
            ->assertStatus(403);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $this->as($this->admin)->putJson("/api/company/members/{$this->adminMembership->id}", ['company_role_id' => $this->viewerRole->id])
            ->assertStatus(422);
    }

    public function test_admin_can_assign_a_role_to_a_member(): void
    {
        $this->as($this->admin)->putJson("/api/company/members/{$this->memberMembership->id}", ['company_role_id' => $this->inviterRole->id])
            ->assertOk();
        $this->assertSame($this->inviterRole->id, $this->memberMembership->fresh()->company_role_id);
    }

    public function test_role_from_another_company_is_rejected_tenant_isolation(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $foreignRole = CompanyRole::create(['company_id' => $other->id, 'key' => 'x', 'name' => 'X', 'is_administrative' => false]);
        $this->expectException(ValidationException::class);
        MembershipSecurityPolicy::assertCanAssignRole($this->company, $foreignRole, $this->admin, $this->memberMembership->fresh());
    }

    // ── Escalade via l'accès collaborateur (gated members.invite = NON-admin) ──

    public function test_non_admin_cannot_grant_admin_role_via_employee_access(): void
    {
        $emp = $this->employee();
        $this->expectException(ValidationException::class);
        app(EmployeeAccessService::class)->grant($this->company, $emp, 'new.hire@co.test', $this->adminRole->id, $this->inviter->id);
    }

    public function test_admin_can_grant_admin_role_via_employee_access_unified_rule(): void
    {
        $emp = $this->employee();
        $res = app(EmployeeAccessService::class)->grant($this->company, $emp, 'new.hire2@co.test', $this->adminRole->id, $this->admin->id);
        $this->assertContains($res['state'], ['invited', 'active']);
        $this->assertTrue((bool) $emp->fresh()->user_id);
    }

    public function test_non_admin_can_still_grant_non_admin_role(): void
    {
        $emp = $this->employee();
        $res = app(EmployeeAccessService::class)->grant($this->company, $emp, 'op.hire@co.test', $this->viewerRole->id, $this->inviter->id);
        $this->assertContains($res['state'], ['invited', 'active']);
    }

    // ── Cycle de vie ──

    public function test_cannot_remove_your_own_membership(): void
    {
        $this->as($this->admin)->deleteJson("/api/company/members/{$this->adminMembership->id}")
            ->assertStatus(422);
    }

    public function test_removing_membership_unlinks_employee_but_keeps_hr_data(): void
    {
        $emp = $this->employee(['user_id' => $this->member->id]);
        $this->as($this->admin)->deleteJson("/api/company/members/{$this->memberMembership->id}")->assertOk();

        $fresh = $emp->fresh();
        $this->assertNull($fresh->user_id, 'employee must be unlinked');
        $this->assertDatabaseHas('workforce_employees', ['id' => $emp->id, 'status' => 'active']); // fiche RH préservée
        $this->assertDatabaseMissing('memberships', ['id' => $this->memberMembership->id]);
    }

    // ── Prévention des divergences d'identité (personnes reliées) ──

    public function test_editing_member_name_propagates_to_linked_employee(): void
    {
        $emp = $this->employee(['user_id' => $this->member->id, 'first_name' => 'Old', 'last_name' => 'Name']);
        $this->as($this->admin)->putJson("/api/company/members/{$this->memberMembership->id}", ['first_name' => 'Alice', 'last_name' => 'Martin'])
            ->assertOk();
        $this->assertSame('Alice', $emp->fresh()->first_name);
        $this->assertSame('Martin', $emp->fresh()->last_name);
    }

    public function test_editing_employee_name_propagates_to_linked_user(): void
    {
        $emp = $this->employee(['user_id' => $this->member->id]);
        LinkedIdentityService::employeeToUser($emp); // simulate post-update hook
        $emp->update(['first_name' => 'Bob', 'last_name' => 'Leroy']);
        LinkedIdentityService::employeeToUser($emp->fresh());
        $this->assertSame('Bob', $this->member->fresh()->first_name);
        $this->assertSame('Leroy', $this->member->fresh()->last_name);
    }

    // ── Isolation / non-régression ──

    public function test_cross_tenant_actor_cannot_be_administrative(): void
    {
        $this->assertFalse(MembershipSecurityPolicy::isAdministrativeActor($this->company, $this->member));
        $this->assertTrue(MembershipSecurityPolicy::isAdministrativeActor($this->company, $this->admin));
        $this->assertTrue(MembershipSecurityPolicy::isAdministrativeActor($this->company, $this->owner));
    }
}
