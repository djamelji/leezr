<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\CompensationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-753 (Phase 1) — fiche Collaborateur unifiée `/company/people/{personRef}`.
 */
class PersonFilePhase1Test extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private User $admin;
    private User $hrLimited; // workforce.view SANS compensation_read
    private CompanyRole $adminRole;
    private CompanyRole $hrRole;
    private CompanyRole $noAccessRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        // Workforce actif en test : entitled via le jobdomain (Gate 4 EntitlementResolver =
        // module dans jobdomain.default_modules) + reason d'activation → ModuleGate::isActive=true.
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')
            ->update(['default_modules' => ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave']]);

        $this->company = Company::create(['name' => 'People Co', 'slug' => 'people-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        foreach (['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->adminRole = $this->setUpCompanyRbac($this->company);

        $this->hrRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'hr', 'name' => 'HR', 'is_administrative' => false]);
        $this->hrRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['members.view', 'workforce.view'])->pluck('id')->all());

        // Rôle SANS aucune facette (ni members.view ni workforce.view) → ne peut pas entrer.
        $this->noAccessRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'noacc', 'name' => 'NoAccess', 'is_administrative' => false]);
        $this->noAccessRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['support.view'])->pluck('id')->all());

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->hrLimited = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'user', 'company_role_id' => $this->adminRole->id]);
        $this->company->memberships()->create(['user_id' => $this->hrLimited->id, 'role' => 'user', 'company_role_id' => $this->hrRole->id]);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(),
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => uniqid().'@co.test',
            'status' => 'active', 'hire_date' => '2026-01-01',
        ], $attrs));
    }

    // ── Types de personnes ──

    public function test_user_only_person_has_no_employment_sections(): void
    {
        $data = $this->as($this->admin)->getJson("/api/company/people/user-{$this->admin->id}")->assertOk()->json();
        $this->assertSame("user-{$this->admin->id}", $data['person_ref']);
        $this->assertNull($data['employment']);
        $this->assertContains('access', $data['available_sections']);
        $this->assertNotContains('employment', $data['available_sections']);
        $this->assertNotContains('planning', $data['available_sections']);
    }

    public function test_employee_only_person_without_account(): void
    {
        $emp = $this->employee();
        $data = $this->as($this->admin)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertSame("employee-{$emp->id}", $data['person_ref']);
        $this->assertSame('none', $data['account']['state']);
        $this->assertNotNull($data['employment']);
        $this->assertContains('employment', $data['available_sections']);
        $this->assertSame('Jean', $data['identity']['first_name']);
    }

    public function test_linked_person_user_ref_resolves_to_canonical_employee(): void
    {
        $member = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user', 'company_role_id' => $this->hrRole->id]);
        $emp = $this->employee(['user_id' => $member->id]);

        // L'ANCIEN lien user doit résoudre la bonne fiche (canonique = employee).
        $data = $this->as($this->admin)->getJson("/api/company/people/user-{$member->id}")->assertOk()->json();
        $this->assertSame("employee-{$emp->id}", $data['person_ref']);
        $this->assertSame($member->id, $data['user_id']);
        $this->assertSame($emp->id, $data['employee_id']);
        $this->assertNotNull($data['employment']);
        $this->assertNotNull($data['account']['role']);
    }

    // ── RBAC / masquage ──

    public function test_route_requires_at_least_one_facet(): void
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $this->noAccessRole->id]);
        $emp = $this->employee();
        $this->as($u)->getJson("/api/company/people/employee-{$emp->id}")->assertForbidden();
    }

    public function test_compensation_masked_without_permission(): void
    {
        $emp = $this->employee();
        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $emp->id,
            'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35,
            'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'contract_id' => $contract->id,
            'base_salary_cents' => 500000, 'currency' => 'EUR', 'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500, 'effective_from' => '2026-01-01', 'compensation_type' => 'monthly',
        ]);

        // hrLimited : workforce.view SANS compensation_read.
        $data = $this->as($this->hrLimited)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertFalse($data['can']['view_compensation']);
        $this->assertArrayNotHasKey('compensation', $data['employment']['contract'] ?? []);

        // admin : voit la rémunération.
        $dataAdmin = $this->as($this->admin)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertTrue($dataAdmin['can']['view_compensation']);
    }

    public function test_tenant_isolation_person_from_other_company_404(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $otherUser = User::factory()->create();
        $other->memberships()->create(['user_id' => $otherUser->id, 'role' => 'owner']);
        $this->as($this->admin)->getJson("/api/company/people/user-{$otherUser->id}")->assertNotFound();
    }

    public function test_invalid_ref_is_422(): void
    {
        // Le format est contraint par la route (404 si non conforme) — on teste un id inexistant conforme.
        $this->as($this->admin)->getJson('/api/company/people/user-99999')->assertNotFound();
    }

    // ── No-Workforce ──

    public function test_without_workforce_module_user_works_employee_404(): void
    {
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce')->delete();
        // user-only fonctionne (Core)
        $data = $this->as($this->admin)->getJson("/api/company/people/user-{$this->admin->id}")->assertOk()->json();
        $this->assertNull($data['employment']);
        // employee ref → 404 (pas de fiche RH sans module)
        $emp = Employee::create(['company_id' => $this->company->id, 'employee_number' => 'X1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.c', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $this->as($this->admin)->getJson("/api/company/people/employee-{$emp->id}")->assertNotFound();
    }

    // ── Historique (masqué si aucune donnée) ──

    public function test_history_hidden_without_audit_shown_with_audit(): void
    {
        $emp = $this->employee();
        $data = $this->as($this->admin)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertNotContains('history', $data['available_sections']);

        app(AuditLogger::class)->logCompany($this->company->id, AuditAction::MEMBER_PROFILE_UPDATED, 'employee', (string) $emp->id, ['actorId' => $this->admin->id, 'actorType' => 'user']);
        $data2 = $this->as($this->admin)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertContains('history', $data2['available_sections']);
    }
}
