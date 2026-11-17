<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-767 (M7 LOT 1.1) — security regressions found by the code audit, each exercised through
 * the REAL HTTP path (route middleware + controller + authority), never through isolated methods.
 *
 *  1. Invitation escalation (non-admin `members.invite` → administrative role).
 *  2. Unguarded member reads (GET /company/members, /{id}, /{id}/fields).
 *  3. `workforce.view` scope (team only) on People list/file and direct employee reads.
 *  4. Compensation/contract authorization + tenant ownership of department/job role/manager.
 *  5. Company-level edits never alter a GLOBAL identity shared with another company.
 */
class Adr767SecurityHardeningTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private Company $other;
    private User $owner;
    private User $admin;
    private User $inviter;      // non-admin: members.view + members.invite
    private User $bystander;    // non-admin: no members/workforce permission
    private User $manager;      // non-admin: workforce.view only (team scope)
    private User $hr;           // non-admin: workforce.view + workforce.manage (company scope)
    private User $contractsAdmin; // administrative role WITHOUT compensation_* permissions
    private CompanyRole $adminRole;
    private CompanyRole $viewerRole;
    private Membership $viewerMembership;
    private Employee $managerEmployee;
    private Employee $report;
    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();

        $workforceModules = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'];
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => $workforceModules]);

        $this->company = Company::create(['name' => 'A Co', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->other = Company::create(['name' => 'B Co', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $this->activateCompanyModules($this->other);
        foreach ([$this->company, $this->other] as $c) {
            foreach ($workforceModules as $m) {
                \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                    ['company_id' => $c->id, 'module_key' => $m],
                    ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT],
                );
            }
        }
        $this->adminRole = $this->setUpCompanyRbac($this->company);
        $this->setUpCompanyRbac($this->other);

        $this->viewerRole = $this->role('viewer', ['members.view']);
        $inviterRole = $this->role('inviter', ['members.view', 'members.invite']);
        $bystanderRole = $this->role('bystander', ['shipments.view']);
        $managerRole = $this->role('manager', ['workforce.view']);
        $hrRole = $this->role('hr', ['workforce.view', 'workforce.manage']);
        $contractsRole = $this->role('contracts', ['workforce.view', 'workforce.manage', 'workforce.contracts'], administrative: true);

        $this->owner = $this->member('owner', null, 'owner');
        $this->admin = $this->member('admin', $this->adminRole);
        $this->inviter = $this->member('inviter', $inviterRole);
        $this->bystander = $this->member('bystander', $bystanderRole);
        $this->manager = $this->member('manager', $managerRole);
        $this->hr = $this->member('hr', $hrRole);
        $this->contractsAdmin = $this->member('contracts', $contractsRole);
        $viewer = $this->member('viewer', $this->viewerRole);
        $this->viewerMembership = Membership::where('company_id', $this->company->id)->where('user_id', $viewer->id)->first();

        $this->managerEmployee = $this->employee($this->company, ['user_id' => $this->manager->id, 'first_name' => 'Mona', 'last_name' => 'Manager']);
        $this->report = $this->employee($this->company, ['manager_id' => $this->managerEmployee->id, 'first_name' => 'Rita', 'last_name' => 'Report']);
        $this->outsider = $this->employee($this->company, ['first_name' => 'Oscar', 'last_name' => 'Outsider']);
    }

    private function role(string $key, array $permissions, bool $administrative = false, ?Company $company = null): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => ($company ?? $this->company)->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $administrative]);
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

    // ═══ 1. Invitation escalation ═══════════════════════════════════════════

    public function test_non_admin_inviter_cannot_invite_into_an_administrative_role(): void
    {
        $email = 'escalation-'.uniqid().'@co.test';

        $this->as($this->inviter)->postJson('/api/company/members', ['email' => $email, 'company_role_id' => $this->adminRole->id])
            ->assertStatus(422);

        // No side effect: neither a membership nor a dangling global account.
        $this->assertNull(User::where('email', $email)->first());
        $this->assertSame(0, Membership::where('company_id', $this->company->id)->where('company_role_id', $this->adminRole->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))->count());
    }

    public function test_non_admin_inviter_can_still_invite_into_a_non_administrative_role(): void
    {
        $this->as($this->inviter)->postJson('/api/company/members', ['email' => 'ok-'.uniqid().'@co.test', 'company_role_id' => $this->viewerRole->id])
            ->assertStatus(201);
    }

    public function test_admin_can_invite_into_an_administrative_role(): void
    {
        $this->as($this->admin)->postJson('/api/company/members', ['email' => 'adm-'.uniqid().'@co.test', 'company_role_id' => $this->adminRole->id])
            ->assertStatus(201);
    }

    public function test_invitation_rejects_a_role_from_another_company(): void
    {
        $foreign = $this->role('foreign', ['members.view'], false, $this->other);

        $this->as($this->admin)->postJson('/api/company/members', ['email' => 'x-'.uniqid().'@co.test', 'company_role_id' => $foreign->id])
            ->assertStatus(422);
    }

    // ═══ 2. Member reads are permission-gated ═══════════════════════════════

    public function test_member_reads_require_a_business_permission(): void
    {
        $id = $this->viewerMembership->id;

        $this->as($this->bystander)->getJson('/api/company/members')->assertStatus(403);
        $this->as($this->bystander)->getJson("/api/company/members/{$id}")->assertStatus(403);
        $this->as($this->bystander)->getJson("/api/company/members/{$id}/fields")->assertStatus(403);
    }

    public function test_legitimate_member_reads_are_preserved(): void
    {
        $id = $this->viewerMembership->id;

        // members.view → list + profile + fields.
        $this->as($this->inviter)->getJson('/api/company/members')->assertOk();
        $this->as($this->inviter)->getJson("/api/company/members/{$id}")->assertOk();
        $this->as($this->inviter)->getJson("/api/company/members/{$id}/fields")->assertOk();

        // Pickers used by other modules keep working: document requests (documents.manage)
        // and shipment assignment (shipments.assign) only need the LIST, never the profile.
        $assigner = $this->member('assigner', $this->role('assigner', ['shipments.view', 'shipments.assign']));
        $this->as($assigner)->getJson('/api/company/members')->assertOk();
        $this->as($assigner)->getJson("/api/company/members/{$id}")->assertStatus(403);
    }

    public function test_member_reads_stay_tenant_isolated(): void
    {
        $foreignUser = $this->member('foreign', null, 'owner', $this->other);
        $foreignMembership = Membership::where('company_id', $this->other->id)->where('user_id', $foreignUser->id)->first();

        $this->as($this->admin)->getJson("/api/company/members/{$foreignMembership->id}")->assertStatus(404);
        $this->as($this->admin)->getJson("/api/company/members/{$foreignMembership->id}/fields")->assertStatus(404);
    }

    // ═══ 3. workforce.view = team scope (self + direct reports) ══════════════

    public function test_people_list_is_team_scoped_for_workforce_view_only(): void
    {
        $refs = collect($this->as($this->manager)->getJson('/api/company/people?per_page=100')->assertOk()->json('data'))
            ->pluck('person_ref')->all();

        $this->assertContains("employee-{$this->managerEmployee->id}", $refs);
        $this->assertContains("employee-{$this->report->id}", $refs);
        $this->assertNotContains("employee-{$this->outsider->id}", $refs, 'An employee outside the team must not be listed.');
    }

    public function test_person_file_by_ref_is_team_scoped_for_workforce_view_only(): void
    {
        $this->as($this->manager)->getJson("/api/company/people/employee-{$this->report->id}")
            ->assertOk()->assertJsonPath('employee_id', $this->report->id);
        $this->as($this->manager)->getJson("/api/company/people/employee-{$this->outsider->id}")->assertStatus(404);
    }

    public function test_direct_employee_reads_are_team_scoped_for_workforce_view_only(): void
    {
        $out = $this->outsider->id;
        $this->as($this->manager)->getJson("/api/workforce/employees/{$out}")->assertStatus(403);
        $this->as($this->manager)->getJson("/api/workforce/employees/{$out}/contracts")->assertStatus(403);
        $this->as($this->manager)->getJson("/api/workforce/employees/{$out}/access")->assertStatus(403);

        // Own team stays readable.
        $this->as($this->manager)->getJson("/api/workforce/employees/{$this->report->id}")->assertOk();
        $this->as($this->manager)->getJson("/api/workforce/employees/{$this->report->id}/contracts")->assertOk();
    }

    public function test_hr_profile_keeps_company_wide_visibility(): void
    {
        $refs = collect($this->as($this->hr)->getJson('/api/company/people?per_page=100')->assertOk()->json('data'))
            ->pluck('person_ref')->all();
        $this->assertContains("employee-{$this->outsider->id}", $refs);

        $this->as($this->hr)->getJson("/api/company/people/employee-{$this->outsider->id}")->assertOk();
        $this->as($this->hr)->getJson("/api/workforce/employees/{$this->outsider->id}")->assertOk();
    }

    public function test_access_facet_never_leaks_hr_data_outside_the_team(): void
    {
        // members.view + workforce.view (no manage): accounts are visible company-wide, HR data is not.
        $mixed = $this->member('mixed', $this->role('mixed', ['members.view', 'workforce.view']));
        $linkedUser = $this->member('linked', $this->viewerRole);
        $linked = $this->employee($this->company, ['user_id' => $linkedUser->id, 'phone' => '0600000000']);

        $file = $this->as($mixed)->getJson("/api/company/people/user-{$linkedUser->id}")->assertOk();
        $this->assertNull($file->json('employment'), 'HR facet must be withheld outside the team.');
        $this->assertNull($file->json('identity.phone'));

        $row = collect($this->as($mixed)->getJson('/api/company/people?per_page=100')->json('data'))
            ->firstWhere('user_id', $linkedUser->id);
        $this->assertNotNull($row, 'The account stays listed through the access facet.');
        $this->assertNull($row['employment_status']);
        $this->assertNotSame("employee-{$linked->id}", $row['person_ref']);
    }

    // ═══ 4. Compensation / contracts / tenant ownership of HR references ════

    private function contractPayload(array $extra = []): array
    {
        return array_merge(['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2026-02-01'], $extra);
    }

    public function test_setting_compensation_requires_compensation_manage(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/contracts";

        $this->as($this->contractsAdmin)->postJson($url, $this->contractPayload(['base_salary_cents' => 500000]))->assertStatus(403);
        $this->assertSame(0, \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()->where('employee_id', $this->outsider->id)->count());
    }

    public function test_contract_response_never_exposes_compensation_without_compensation_read(): void
    {
        $url = "/api/workforce/employees/{$this->outsider->id}/contracts";

        $res = $this->as($this->contractsAdmin)->postJson($url, $this->contractPayload())->assertStatus(201);
        $this->assertArrayNotHasKey('compensation_plans', $res->json());

        // A full admin still creates WITH compensation and reads it back.
        $res = $this->as($this->admin)->postJson("/api/workforce/employees/{$this->report->id}/contracts", $this->contractPayload(['base_salary_cents' => 320000]))->assertStatus(201);
        $this->assertSame(320000, $res->json('compensation_plans.0.base_salary_cents'));
    }

    public function test_employee_references_must_belong_to_the_company(): void
    {
        $foreignDept = Department::create(['company_id' => $this->other->id, 'name' => 'Foreign dept']);
        $foreignJob = JobRole::create(['company_id' => $this->other->id, 'title' => 'Foreign job']);
        $foreignManager = $this->employee($this->other);
        $url = "/api/workforce/employees/{$this->report->id}";

        $this->as($this->hr)->putJson($url, ['department_id' => $foreignDept->id])->assertStatus(422);
        $this->as($this->hr)->putJson($url, ['job_role_id' => $foreignJob->id])->assertStatus(422);
        $this->as($this->hr)->putJson($url, ['manager_id' => $foreignManager->id])->assertStatus(422);

        $this->as($this->hr)->postJson('/api/workforce/employees', [
            'first_name' => 'New', 'last_name' => 'Hire', 'hire_date' => '2026-01-01', 'department_id' => $foreignDept->id,
        ])->assertStatus(422);

        $fresh = $this->report->fresh();
        $this->assertNull($fresh->department_id);
        $this->assertSame($this->managerEmployee->id, $fresh->manager_id);

        // Own-company references remain accepted.
        $dept = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);
        $this->as($this->hr)->putJson($url, ['department_id' => $dept->id])->assertOk();
    }

    // ═══ 5. A company never rewrites a GLOBAL identity shared with another company ═══

    private function sharedUser(): array
    {
        $shared = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Shared', 'password' => Hash::make('original-Password-1!')]);
        $this->other->memberships()->create(['user_id' => $shared->id, 'role' => 'owner']);
        $membership = $this->company->memberships()->create(['user_id' => $shared->id, 'role' => 'user', 'company_role_id' => $this->viewerRole->id]);

        return [$shared, $membership];
    }

    public function test_company_admin_cannot_rename_a_user_shared_with_another_company(): void
    {
        [$shared, $membership] = $this->sharedUser();

        $this->as($this->admin)->putJson("/api/company/members/{$membership->id}", ['first_name' => 'Hacked'])->assertStatus(422);
        $this->assertSame('Sam', $shared->fresh()->first_name);
    }

    public function test_company_admin_cannot_set_the_password_of_a_user_shared_with_another_company(): void
    {
        [$shared, $membership] = $this->sharedUser();
        $before = $shared->password;

        $this->as($this->admin)->putJson("/api/company/members/{$membership->id}/password", [
            'password' => 'Takeover-Passw0rd!', 'password_confirmation' => 'Takeover-Passw0rd!',
        ])->assertStatus(422);

        $this->assertSame($before, $shared->fresh()->password, 'Cross-tenant account takeover must be impossible.');
    }

    public function test_hr_edit_of_a_linked_employee_does_not_rename_a_shared_user(): void
    {
        [$shared] = $this->sharedUser();
        $employee = $this->employee($this->company, ['user_id' => $shared->id, 'first_name' => 'Sam', 'last_name' => 'Shared']);

        $this->as($this->hr)->putJson("/api/workforce/employees/{$employee->id}", ['first_name' => 'Samuel'])->assertOk();

        $this->assertSame('Samuel', $employee->fresh()->first_name, 'The company-local HR record is editable.');
        $this->assertSame('Sam', $shared->fresh()->first_name, 'The global identity is left untouched.');
    }

    public function test_single_company_identity_remains_editable_by_its_company(): void
    {
        $solo = User::factory()->create(['first_name' => 'Solo', 'last_name' => 'User']);
        $membership = $this->company->memberships()->create(['user_id' => $solo->id, 'role' => 'user', 'company_role_id' => $this->viewerRole->id]);

        $this->as($this->admin)->putJson("/api/company/members/{$membership->id}", ['first_name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $solo->fresh()->first_name);

        $this->as($this->admin)->putJson("/api/company/members/{$membership->id}/password", [
            'password' => 'Legit-Passw0rd!42', 'password_confirmation' => 'Legit-Passw0rd!42',
        ])->assertOk();
    }

    // ═══ 6. Legitimate HR profiles keep reaching People (nav regression since ADR-754) ═══

    private function peopleEntries(array $permissions, string $roleLevel): int
    {
        return collect(\App\Core\Navigation\NavBuilder::forCompany($this->company, $permissions, $roleLevel))
            ->flatMap(fn ($group) => $group['items'])
            // ADR-818 : un espace à plusieurs entrées (Équipe = Collaborateurs + Embauches) porte ses entrées en enfants.
            ->flatMap(fn ($item) => empty($item['children']) ? [$item] : $item['children'])
            ->filter(fn ($item) => ($item['to']['name'] ?? null) === 'company-people')
            ->count();
    }

    public function test_non_administrative_hr_profile_reaches_people_exactly_once(): void
    {
        // Non-administrative manager/HR (roleLevel operational) holding the HR facet.
        $this->assertSame(1, $this->peopleEntries(['workforce.view'], 'operational'));
        $this->assertSame(1, $this->peopleEntries(['workforce.view', 'workforce.manage'], 'operational'));

        // Never duplicated for management, never granted without the HR facet.
        $this->assertSame(1, $this->peopleEntries(['members.view', 'workforce.view'], 'management'));
        $this->assertSame(1, $this->peopleEntries(['workforce.view'], 'management'));
        $this->assertSame(0, $this->peopleEntries(['shipments.view'], 'operational'));
        $this->assertSame(0, $this->peopleEntries(['members.view'], 'operational'), 'Access facet stays a structure (management) entry.');
    }
}
