<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-768 (M7 LOT 2) — the operational person file. Every mutation is exercised through the REAL
 * HTTP path (route middleware + controller + authority), then checked in the database, read back
 * through the People read model, and checked for the absence of any effect on another company.
 */
class M7Lot2OperationalPersonFileTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private Company $other;
    private CompanyRole $adminRole;
    private CompanyRole $viewerRole;
    private User $owner;
    private User $admin;
    private User $accessAdmin;  // members.* (administrative), no workforce
    private User $viewer;       // members.view only
    private User $hr;           // workforce.view + workforce.manage
    private User $hrPicker;     // hr + members.view
    private User $hrLinker;     // hr + members.view + members.manage (administrative)
    private User $manager;      // workforce.view only (team scope)
    private User $contracts;    // workforce.contracts, no compensation
    private User $comp;         // contracts + compensation_read/manage
    private User $docViewer;    // documents.view + members.view
    private User $payroll;      // payslips.download + members.view, NO documents.view
    private User $docManager;   // documents.manage only (selector profile)
    private Employee $managerEmployee;
    private Employee $report;
    private Employee $outsider;
    private User $otherMember;
    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();

        $modules = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'];
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);

        $this->company = Company::create(['name' => 'A Co', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->other = Company::create(['name' => 'B Co', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->company, $this->other] as $c) {
            $this->activateCompanyModules($c);
            foreach ($modules as $m) {
                \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                    ['company_id' => $c->id, 'module_key' => $m],
                    ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT],
                );
            }
        }
        $this->adminRole = $this->setUpCompanyRbac($this->company);
        $otherAdminRole = $this->setUpCompanyRbac($this->other);

        $this->viewerRole = $this->role('viewer', ['members.view']);
        $this->owner = $this->member('owner', null, 'owner');
        $this->admin = $this->member('admin', $this->adminRole);
        $this->accessAdmin = $this->member('access', $this->role('access', ['members.view', 'members.manage', 'members.invite', 'members.credentials'], true));
        $this->viewer = $this->member('viewer', $this->viewerRole);
        $this->hr = $this->member('hr', $this->role('hr', ['workforce.view', 'workforce.manage']));
        $this->hrPicker = $this->member('hrpicker', $this->role('hrpicker', ['workforce.view', 'workforce.manage', 'members.view']));
        $this->hrLinker = $this->member('hrlinker', $this->role('hrlinker', ['workforce.view', 'workforce.manage', 'members.view', 'members.manage'], true));
        $this->manager = $this->member('manager', $this->role('manager', ['workforce.view']));
        $this->contracts = $this->member('contracts', $this->role('contracts', ['workforce.view', 'workforce.manage', 'workforce.contracts'], true));
        $this->comp = $this->member('comp', $this->role('comp', ['workforce.view', 'workforce.manage', 'workforce.contracts', 'workforce.compensation_read', 'workforce.compensation_manage'], true));
        $this->docViewer = $this->member('docviewer', $this->role('docviewer', ['documents.view', 'members.view']));
        $this->payroll = $this->member('payroll', $this->role('payroll', ['payslips.download', 'members.view'], true));
        $this->docManager = $this->member('docmanager', $this->role('docmanager', ['documents.manage'], true));

        $this->managerEmployee = $this->employee($this->company, ['user_id' => $this->manager->id, 'first_name' => 'Mona', 'last_name' => 'Manager']);
        $this->report = $this->employee($this->company, ['manager_id' => $this->managerEmployee->id, 'first_name' => 'Rita', 'last_name' => 'Report']);
        $this->outsider = $this->employee($this->company, ['first_name' => 'Oscar', 'last_name' => 'Outsider']);

        $this->otherMember = $this->member('othermember', $otherAdminRole, 'user', $this->other);
        $this->otherEmployee = $this->employee($this->other, ['first_name' => 'Olga', 'last_name' => 'Other']);
    }

    private function role(string $key, array $permissions, bool $administrative = false): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function member(string $name, ?CompanyRole $role, string $baseRole = 'user', ?Company $company = null): User
    {
        $user = User::factory()->create(['first_name' => ucfirst($name), 'last_name' => 'Test']);
        ($company ?? $this->company)->memberships()->create(['user_id' => $user->id, 'role' => $baseRole, 'company_role_id' => $role?->id]);

        return $user;
    }

    private function employee(Company $company, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $company->id, 'employee_number' => 'E'.uniqid(),
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => uniqid().'@co.test', 'status' => 'active',
            'hire_date' => '2026-01-01',
        ], $attrs));
    }

    private function as(User $u, ?Company $company = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    private function membershipOf(User $u, ?Company $company = null): Membership
    {
        return Membership::withoutGlobalScopes()->where('company_id', ($company ?? $this->company)->id)->where('user_id', $u->id)->firstOrFail();
    }

    private function file(User $actor, string $ref)
    {
        return $this->as($actor)->getJson("/api/company/people/{$ref}");
    }

    private function contractPayload(array $over = []): array
    {
        return array_merge(['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2026-02-01', 'weekly_hours' => 35], $over);
    }

    // ═══ Contracts ══════════════════════════════════════════════════════════

    public function test_overlapping_contract_is_a_readable_422_and_nothing_is_written(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/contracts";

        $this->as($this->contracts)->postJson($url, $this->contractPayload())->assertStatus(201);
        $this->as($this->contracts)->postJson($url, $this->contractPayload(['start_date' => '2026-06-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date']);

        $this->assertSame(1, EmploymentContract::withoutGlobalScopes()->where('employee_id', $this->outsider->id)->count());

        // Read back through the contract list and the person file.
        $this->as($this->contracts)->getJson($url)->assertOk()->assertJsonCount(1);
        $this->file($this->contracts, "employee-{$this->outsider->id}")->assertOk()->assertJsonPath('employment.contract.contract_type', 'cdi');
    }

    public function test_a_non_overlapping_succession_is_still_allowed(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/contracts";

        $this->as($this->contracts)->postJson($url, $this->contractPayload(['contract_type' => 'cdd', 'end_date' => '2026-03-31']))->assertStatus(201);
        $this->as($this->contracts)->postJson($url, $this->contractPayload(['start_date' => '2026-04-01']))->assertStatus(201);
    }

    public function test_compensation_is_absent_from_the_file_without_the_read_permission(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/contracts";
        $this->as($this->comp)->postJson($url, $this->contractPayload(['base_salary_cents' => 250000]))->assertStatus(201);
        $contractId = EmploymentContract::withoutGlobalScopes()->where('employee_id', $this->outsider->id)->value('id');
        $this->as($this->comp)->postJson("{$url}/{$contractId}/activate")->assertOk();
        $ref = "employee-{$this->outsider->id}";

        $reader = $this->file($this->comp, $ref)->assertOk();
        $reader->assertJsonPath('can.view_compensation', true)->assertJsonPath('can.manage_compensation', true);
        $this->assertSame(250000, $reader->json('employment.contract.compensation.base_salary_cents'));

        foreach ([$this->contracts, $this->hr] as $actor) {
            $res = $this->file($actor, $ref)->assertOk();
            $res->assertJsonPath('can.view_compensation', false)->assertJsonPath('can.manage_compensation', false);
            $this->assertStringNotContainsString('250000', $res->getContent());
            $this->assertStringNotContainsString('base_salary_cents', $res->getContent());
            $this->assertStringNotContainsString('250000', $this->as($actor)->getJson($url)->assertOk()->getContent());
        }

        // `workforce.contracts` creates a contract but may not decide the salary.
        $this->as($this->contracts)->postJson("/api/workforce/employees/{$this->report->id}/contracts", $this->contractPayload(['base_salary_cents' => 1]))->assertStatus(403);
        $this->file($this->contracts, $ref)->assertJsonPath('can.manage_contracts', true);
        $this->file($this->hr, $ref)->assertJsonPath('can.manage_contracts', false);
    }

    // ═══ Facets of the file ═════════════════════════════════════════════════

    public function test_access_facet_alone_never_carries_hr_data(): void
    {
        $res = $this->file($this->viewer, "user-{$this->manager->id}")->assertOk();

        $res->assertJsonPath('can.view_access', true)->assertJsonPath('can.view_hr', false)
            ->assertJsonPath('can.edit_identity', false)->assertJsonPath('can.suspend_access', false)
            ->assertJsonPath('can.manage_credentials', false)->assertJsonPath('employment', null)
            ->assertJsonPath('identity.phone', null);
        foreach (['employment', 'contract', 'planning', 'time', 'leave', 'payslips'] as $section) {
            $this->assertNotContains($section, $res->json('available_sections'));
        }
    }

    public function test_hr_facet_alone_never_carries_access_data(): void
    {
        $res = $this->file($this->hr, "employee-{$this->managerEmployee->id}")->assertOk();

        $res->assertJsonPath('can.view_hr', true)->assertJsonPath('can.edit_hr', true)->assertJsonPath('can.view_access', false)
            ->assertJsonPath('account.role', null)->assertJsonPath('account.effective_permissions', null)
            ->assertJsonPath('can.change_role', false)->assertJsonPath('can.edit_identity', false);
        $this->assertNotContains('access', $res->json('available_sections'));
        $this->assertContains('employment', $res->json('available_sections'));
    }

    public function test_manager_is_limited_to_his_team(): void
    {
        $this->file($this->manager, "employee-{$this->report->id}")->assertOk()
            ->assertJsonPath('can.view_hr', true)->assertJsonPath('can.edit_hr', false)->assertJsonPath('can.manage_contracts', false);
        $this->file($this->manager, "employee-{$this->outsider->id}")->assertStatus(404);
        $this->as($this->manager)->getJson("/api/workforce/employees/{$this->outsider->id}")->assertStatus(403);
    }

    public function test_admin_who_is_also_an_employee_has_both_facets_on_one_file(): void
    {
        $emp = $this->employee($this->company, ['user_id' => $this->admin->id, 'first_name' => 'Admin', 'last_name' => 'Test']);

        $res = $this->file($this->owner, "user-{$this->admin->id}")->assertOk();
        $res->assertJsonPath('person_ref', "employee-{$emp->id}")->assertJsonPath('user_id', $this->admin->id)
            ->assertJsonPath('can.view_access', true)->assertJsonPath('can.view_hr', true);
        $this->assertContains('access', $res->json('available_sections'));
        $this->assertContains('employment', $res->json('available_sections'));
    }

    // ═══ Payslips ═══════════════════════════════════════════════════════════

    public function test_payslips_follow_the_payroll_authority_only(): void
    {
        $ref = "employee-{$this->outsider->id}";

        // documents.view / members.view never open the payslips of someone else.
        foreach ([$this->docViewer, $this->viewer] as $actor) {
            $this->as($actor)->getJson("/api/company/people/{$ref}/payslips")->assertStatus(403);
        }
        $this->as($this->docViewer)->getJson("/api/company/people/{$ref}/payslips/1/download")->assertStatus(404);
        $this->assertNotContains('payslips', $this->file($this->docViewer, "user-{$this->viewer->id}")->json('available_sections'));
        $this->file($this->docViewer, "user-{$this->viewer->id}")->assertJsonPath('can.view_payslips', false);

        // A payroll profile WITHOUT documents.view reaches them (no stray 403) and sees the section.
        $this->as($this->payroll)->getJson("/api/company/people/user-{$this->viewer->id}/payslips")->assertOk()->assertJsonPath('payslips', []);
        $res = $this->file($this->payroll, "user-{$this->viewer->id}")->assertOk()->assertJsonPath('can.view_payslips', true);
        $this->assertContains('payslips', $res->json('available_sections'));
    }

    // ═══ Account access lifecycle (account without HR record) ════════════════

    public function test_account_only_access_can_be_suspended_and_restored(): void
    {
        $m = $this->membershipOf($this->viewer);

        $this->as($this->viewer)->postJson("/api/company/members/{$m->id}/suspend")->assertStatus(403);
        $this->as($this->accessAdmin)->postJson("/api/company/members/{$m->id}/suspend", ['reason' => 'Audit'])->assertOk()->assertJsonPath('suspended', true);

        $this->assertNotNull($m->fresh()->suspended_at);
        $this->assertSame('Audit', $m->fresh()->suspension_reason);
        $this->file($this->accessAdmin, "user-{$this->viewer->id}")->assertOk()
            ->assertJsonPath('account.state', 'suspended')->assertJsonPath('account.has_access', false);
        $this->as($this->viewer)->getJson('/api/company/people')->assertStatus(403);

        $this->as($this->accessAdmin)->postJson("/api/company/members/{$m->id}/restore")->assertOk()->assertJsonPath('suspended', false);
        $this->assertNull($m->fresh()->suspended_at);
        $this->as($this->viewer)->getJson('/api/company/people')->assertOk();
    }

    public function test_owner_self_and_foreign_memberships_are_protected(): void
    {
        $this->as($this->accessAdmin)->postJson('/api/company/members/'.$this->membershipOf($this->owner)->id.'/suspend')->assertStatus(422);
        $this->as($this->accessAdmin)->postJson('/api/company/members/'.$this->membershipOf($this->accessAdmin)->id.'/suspend')->assertStatus(422);

        $foreign = $this->membershipOf($this->otherMember, $this->other);
        $this->as($this->accessAdmin)->postJson("/api/company/members/{$foreign->id}/suspend")->assertStatus(404);
        $this->as($this->accessAdmin)->deleteJson("/api/company/members/{$foreign->id}")->assertStatus(404);
        $this->assertNull($foreign->fresh()->suspended_at);

        $self = $this->file($this->accessAdmin, "user-{$this->accessAdmin->id}")->assertOk();
        $self->assertJsonPath('can.suspend_access', false)->assertJsonPath('can.remove_access', false)->assertJsonPath('can.manage_credentials', false);
        $this->file($this->accessAdmin, "user-{$this->owner->id}")->assertJsonPath('can.suspend_access', false)->assertJsonPath('can.remove_access', false);
    }

    public function test_last_administrator_cannot_lose_access(): void
    {
        // A company whose only administrator is `solo` (no owner membership).
        $solo = Company::create(['name' => 'Solo', 'slug' => 'solo-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($solo);
        $role = $this->setUpCompanyRbac($solo);
        $admin = $this->member('solo', $role, 'user', $solo);
        $m = $this->membershipOf($admin, $solo);

        $this->assertTrue(\App\Company\RBAC\MembershipSecurityPolicy::isLastActiveAdmin($solo, $m));
        $this->as($admin, $solo)->postJson("/api/company/members/{$m->id}/suspend")->assertStatus(422);
        $this->as($admin, $solo)->deleteJson("/api/company/members/{$m->id}")->assertStatus(422);
        $this->assertNotNull(Membership::withoutGlobalScopes()->find($m->id));
        $this->assertNull($m->fresh()->suspended_at);
    }

    public function test_removing_an_access_keeps_the_hr_record(): void
    {
        $emp = $this->employee($this->company, ['user_id' => $this->viewer->id]);
        $m = $this->membershipOf($this->viewer);

        $this->as($this->accessAdmin)->deleteJson("/api/company/members/{$m->id}")->assertOk();

        $this->assertNull(Membership::withoutGlobalScopes()->find($m->id));
        $this->assertNull($emp->fresh()->user_id);
        $this->assertNotNull(User::find($this->viewer->id));
        $this->file($this->hr, "employee-{$emp->id}")->assertOk()->assertJsonPath('account.state', 'none');
    }

    public function test_invitation_can_be_resent_only_while_it_is_pending(): void
    {
        $email = 'new-'.uniqid().'@co.test';
        $this->as($this->accessAdmin)->postJson('/api/company/members', ['email' => $email, 'company_role_id' => $this->viewerRole->id])
            ->assertStatus(201)->assertJsonPath('pending_acceptance', false);
        $invited = User::where('email', $email)->firstOrFail();
        $m = $this->membershipOf($invited);

        $this->file($this->accessAdmin, "user-{$invited->id}")->assertJsonPath('account.state', 'invited');
        $this->as($this->accessAdmin)->postJson("/api/company/members/{$m->id}/resend-invitation")->assertOk();
        Notification::assertSentToTimes($invited, \Illuminate\Auth\Notifications\ResetPassword::class, 2);

        // An account that already defined its password has nothing to re-send.
        $this->as($this->accessAdmin)->postJson('/api/company/members/'.$this->membershipOf($this->viewer)->id.'/resend-invitation')->assertStatus(422);
    }

    public function test_existing_account_is_invited_not_activated(): void
    {
        $res = $this->as($this->accessAdmin)->postJson('/api/company/members', ['email' => $this->otherMember->email, 'company_role_id' => $this->viewerRole->id])
            ->assertStatus(201)->assertJsonPath('pending_acceptance', true);
        $m = Membership::withoutGlobalScopes()->findOrFail($res->json('member.id'));

        $this->file($this->accessAdmin, "user-{$this->otherMember->id}")->assertOk()
            ->assertJsonPath('account.state', 'pending_acceptance')->assertJsonPath('account.has_access', false);
        $this->as($this->otherMember)->getJson('/api/company/people')->assertStatus(403);

        // An administrator may neither lift the pending state nor take over the credentials.
        $this->as($this->accessAdmin)->postJson("/api/company/members/{$m->id}/restore")->assertStatus(422);
        $this->as($this->accessAdmin)->putJson("/api/company/members/{$m->id}/password", ['password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#'])->assertStatus(422);
        $this->assertNotNull($m->fresh()->suspended_at);

        // The other company is untouched.
        $this->assertNull($this->membershipOf($this->otherMember, $this->other)->suspended_at);
        $this->as($this->otherMember, $this->other)->getJson('/api/company/people')->assertOk();
    }

    // ═══ Identity ═══════════════════════════════════════════════════════════

    public function test_identity_edit_persists_and_is_read_back_from_the_file(): void
    {
        $m = $this->membershipOf($this->viewer);

        $this->as($this->viewer)->putJson("/api/company/members/{$m->id}", ['first_name' => 'X'])->assertStatus(403);
        $this->as($this->accessAdmin)->putJson("/api/company/members/{$m->id}", ['first_name' => 'Victor', 'last_name' => 'Viewer'])->assertOk();

        $this->assertSame('Victor', $this->viewer->fresh()->first_name);
        $this->file($this->accessAdmin, "user-{$this->viewer->id}")->assertOk()
            ->assertJsonPath('identity.first_name', 'Victor')->assertJsonPath('identity.display_name', 'Victor Viewer')
            ->assertJsonPath('identity_shared', false)->assertJsonPath('can.edit_global_name', true)->assertJsonPath('can.set_password', true);
    }

    public function test_shared_global_identity_is_locked_from_this_company(): void
    {
        // `viewer` also belongs to the other company : the global identity is shared.
        $this->other->memberships()->create(['user_id' => $this->viewer->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $this->other->id)->value('id')]);
        $m = $this->membershipOf($this->viewer);
        $before = $this->viewer->only('first_name', 'last_name', 'password');

        $this->file($this->accessAdmin, "user-{$this->viewer->id}")->assertOk()
            ->assertJsonPath('identity_shared', true)->assertJsonPath('can.edit_identity', true)
            ->assertJsonPath('can.edit_global_name', false)->assertJsonPath('can.set_password', false)->assertJsonPath('can.manage_credentials', true);

        $this->as($this->accessAdmin)->putJson("/api/company/members/{$m->id}", ['first_name' => 'Hijack'])->assertStatus(422);
        $this->as($this->accessAdmin)->putJson("/api/company/members/{$m->id}/password", ['password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#'])->assertStatus(422);

        $this->assertSame($before, $this->viewer->fresh()->only('first_name', 'last_name', 'password'));
        $this->file($this->otherMember, "user-{$this->viewer->id}")->assertStatus(403); // not a member of company A
        $this->as($this->otherMember, $this->other)->getJson("/api/company/people/user-{$this->viewer->id}")->assertOk()
            ->assertJsonPath('identity.first_name', $before['first_name']);

        // A role change (company-level data) stays possible on a shared identity.
        $role = $this->role('second', ['members.view']);
        $this->as($this->accessAdmin)->putJson("/api/company/members/{$m->id}", ['company_role_id' => $role->id])->assertOk();
        $this->assertSame($role->id, $m->fresh()->company_role_id);
    }

    public function test_role_change_from_the_file_goes_through_the_security_policy(): void
    {
        $m = $this->membershipOf($this->viewer);
        $nonAdminManager = $this->member('rolemgr', $this->role('rolemgr', ['members.view', 'members.invite']));

        // Without members.manage : refused by the route. Administrative role by a non-administrative actor : refused by the policy.
        $this->as($nonAdminManager)->putJson("/api/company/members/{$m->id}", ['company_role_id' => $this->adminRole->id])->assertStatus(403);
        $this->as($this->admin)->putJson("/api/company/members/{$m->id}", ['company_role_id' => $this->adminRole->id])->assertOk();
        $this->file($this->admin, "user-{$this->viewer->id}")->assertJsonPath('account.role.is_administrative', true)->assertJsonPath('account.is_admin', true);
    }

    // ═══ Explicit account ↔ HR record bridge ════════════════════════════════

    public function test_hr_record_can_be_created_for_an_existing_member(): void
    {
        $otherCount = Employee::withoutGlobalScopes()->where('company_id', $this->other->id)->count();

        $this->file($this->hrPicker, "user-{$this->viewer->id}")->assertOk()->assertJsonPath('can.create_hr_record', true)->assertJsonPath('employee_id', null);
        $this->file($this->hr, "employee-{$this->outsider->id}")->assertJsonPath('can.create_hr_record', false);

        // hr without members.view may not pick an account.
        $this->as($this->hr)->postJson('/api/workforce/employees/from-member', ['user_id' => $this->viewer->id, 'hire_date' => '2026-03-01'])->assertStatus(403);
        // An account of another company is not a member here.
        $this->as($this->hrPicker)->postJson('/api/workforce/employees/from-member', ['user_id' => $this->otherMember->id, 'hire_date' => '2026-03-01'])->assertStatus(422);

        $id = $this->as($this->hrPicker)->postJson('/api/workforce/employees/from-member', ['user_id' => $this->viewer->id, 'hire_date' => '2026-03-01', 'employee_number' => 'M-77'])
            ->assertStatus(201)->json('id');

        $emp = Employee::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame([$this->company->id, $this->viewer->id, 'Viewer', 'Test', 'M-77'], [$emp->company_id, $emp->user_id, $emp->first_name, $emp->last_name, $emp->employee_number]);
        $this->file($this->hrPicker, "user-{$this->viewer->id}")->assertOk()
            ->assertJsonPath('person_ref', "employee-{$id}")->assertJsonPath('can.create_hr_record', false);

        // One HR record per account.
        $this->as($this->hrPicker)->postJson('/api/workforce/employees/from-member', ['user_id' => $this->viewer->id, 'hire_date' => '2026-03-01'])->assertStatus(422);
        $this->assertSame($otherCount, Employee::withoutGlobalScopes()->where('company_id', $this->other->id)->count());
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('user_id', $this->viewer->id)->count());
    }

    public function test_hr_record_can_be_linked_to_an_existing_member_explicitly(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/link-account";

        $this->file($this->hrLinker, "employee-{$this->outsider->id}")->assertJsonPath('can.link_account', true);
        $this->file($this->hrPicker, "employee-{$this->outsider->id}")->assertJsonPath('can.link_account', false);

        $candidates = collect($this->as($this->hrLinker)->getJson('/api/workforce/employees/link-candidates')->assertOk()->json('candidates'))->pluck('user_id');
        $this->assertTrue($candidates->contains($this->viewer->id));
        $this->assertFalse($candidates->contains($this->manager->id));     // already has an HR record
        $this->assertFalse($candidates->contains($this->otherMember->id)); // other company

        $this->as($this->hrPicker)->postJson($url, ['user_id' => $this->viewer->id])->assertStatus(403);            // needs members.manage
        $this->as($this->hrLinker)->postJson($url, ['user_id' => $this->otherMember->id])->assertStatus(422);       // not a member here
        $this->as($this->hrLinker)->postJson($url, ['user_id' => $this->manager->id])->assertStatus(422);           // account already linked
        $this->as($this->hrLinker)->postJson("/api/workforce/employees/{$this->otherEmployee->id}/link-account", ['user_id' => $this->viewer->id])->assertStatus(404);
        $this->assertNull($this->outsider->fresh()->user_id);

        $this->as($this->hrLinker)->postJson($url, ['user_id' => $this->viewer->id])->assertOk()->assertJsonPath('user_id', $this->viewer->id);

        $this->assertSame($this->viewer->id, $this->outsider->fresh()->user_id);
        $this->assertSame('Oscar', $this->outsider->fresh()->first_name);       // HR identity untouched
        $this->assertSame('Viewer', $this->viewer->fresh()->first_name);        // global identity untouched
        $this->assertNull($this->otherEmployee->fresh()->user_id);
        $this->file($this->hrLinker, "user-{$this->viewer->id}")->assertOk()->assertJsonPath('person_ref', "employee-{$this->outsider->id}");
        $this->as($this->hrLinker)->postJson($url, ['user_id' => $this->viewer->id])->assertStatus(422);            // already linked
    }

    public function test_hr_references_are_limited_to_the_active_company(): void
    {
        $dept = \App\Core\Workforce\Department::create(['company_id' => $this->other->id, 'name' => 'Foreign']);

        $this->as($this->hr)->putJson("/api/workforce/employees/{$this->outsider->id}", ['department_id' => $dept->id])->assertStatus(422);
        $this->as($this->hr)->putJson("/api/workforce/employees/{$this->outsider->id}", ['manager_id' => $this->otherEmployee->id])->assertStatus(422);
        $this->as($this->hrPicker)->postJson('/api/workforce/employees/from-member', ['user_id' => $this->viewer->id, 'hire_date' => '2026-03-01', 'department_id' => $dept->id])->assertStatus(422);

        $this->as($this->hr)->putJson("/api/workforce/employees/{$this->outsider->id}", ['manager_id' => $this->managerEmployee->id, 'employee_number' => 'M-9'])->assertOk();
        $this->assertSame($this->managerEmployee->id, $this->outsider->fresh()->manager_id);
        $this->file($this->hr, "employee-{$this->outsider->id}")->assertJsonPath('employment.position.manager', 'Mona Manager')
            ->assertJsonPath('employment.identity.employee_number', 'M-9');
    }

    // ═══ Legacy ids → person reference ══════════════════════════════════════

    public function test_legacy_ids_are_resolved_in_their_own_space(): void
    {
        $m = $this->membershipOf($this->viewer);
        $linked = $this->membershipOf($this->manager);

        $this->as($this->accessAdmin)->getJson("/api/company/people/resolve?membership_id={$m->id}")->assertOk()->assertJsonPath('person_ref', "user-{$this->viewer->id}");
        $this->as($this->accessAdmin)->getJson("/api/company/people/resolve?membership_id={$linked->id}")->assertOk()->assertJsonPath('person_ref', "employee-{$this->managerEmployee->id}");
        $this->as($this->hr)->getJson("/api/company/people/resolve?employee_id={$this->outsider->id}")->assertOk()->assertJsonPath('person_ref', "employee-{$this->outsider->id}");

        // A membership id is never read as an employee id (and conversely).
        $this->assertNotSame("employee-{$m->id}", $this->as($this->accessAdmin)->getJson("/api/company/people/resolve?membership_id={$m->id}")->json('person_ref'));

        // Each id space needs its own facet ; ids of another company do not resolve.
        $this->as($this->hr)->getJson("/api/company/people/resolve?membership_id={$m->id}")->assertStatus(403);
        $this->as($this->viewer)->getJson("/api/company/people/resolve?employee_id={$this->outsider->id}")->assertStatus(403);
        $this->as($this->accessAdmin)->getJson('/api/company/people/resolve?membership_id='.$this->membershipOf($this->otherMember, $this->other)->id)->assertStatus(404);
        $this->as($this->hr)->getJson("/api/company/people/resolve?employee_id={$this->otherEmployee->id}")->assertStatus(404);
        $this->as($this->accessAdmin)->getJson('/api/company/people/resolve')->assertStatus(422);
    }

    // ═══ Person pickers ═════════════════════════════════════════════════════

    public function test_selector_profiles_get_a_minimal_members_directory(): void
    {
        $res = $this->as($this->docManager)->getJson('/api/company/members')->assertOk();
        $row = collect($res->json('members'))->firstWhere('user.id', $this->viewer->id);

        $this->assertSame(['id', 'first_name', 'last_name', 'display_name'], array_keys($row['user']));
        foreach (['profile_completeness', 'employee_id', 'is_employee', 'created_at'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->assertArrayNotHasKey('member_limit', $res->json());
        $this->assertStringNotContainsString($this->viewer->email, $res->getContent());

        // The profile and the dynamic fields stay closed to a selector profile.
        $this->as($this->docManager)->getJson('/api/company/members/'.$this->membershipOf($this->viewer)->id)->assertStatus(403);

        // The members facet keeps the full directory.
        $full = collect($this->as($this->viewer)->getJson('/api/company/members')->assertOk()->json('members'))->firstWhere('user.id', $this->viewer->id);
        $this->assertSame($this->viewer->email, $full['user']['email']);
        $this->assertArrayHasKey('profile_completeness', $full);
    }
}
