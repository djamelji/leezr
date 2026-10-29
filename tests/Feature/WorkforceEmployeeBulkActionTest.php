<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G2 (ADR-642) — actions groupées sûres : changement département/poste/responsable. Garde-fous
 * tenant/scope/cycle, bilan par collaborateur, RBAC. Jamais de contrat/rémunération/sortie.
 */
class WorkforceEmployeeBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $deptA;
    private Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Bulk Co', 'slug' => 'bulk-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);

        $this->deptA = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'A', 'sort_order' => 0]);
        $this->deptB = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'B', 'sort_order' => 1]);
    }

    private function emp(string $first, ?int $managerId = null, ?int $deptId = null, string $status = 'active'): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => $first, 'last_name' => 'X',
            'status' => $status, 'hire_date' => '2026-01-01', 'manager_id' => $managerId,
            'department_id' => $deptId, 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function user(array $perms, ?Employee $asEmployee = null): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            $asEmployee->update(['user_id' => $user->id]);
        }

        return $user;
    }

    private function postApi(User $u, string $path, array $body)
    {
        return $this->actingAs($u)->postJson('/api'.$path, $body, ['X-Company-Id' => $this->company->id]);
    }

    public function test_bulk_set_department_updates_eligible(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $e1 = $this->emp('Un', null, $this->deptA->id);
        $e2 = $this->emp('Deux', null, $this->deptA->id);

        $res = $this->postApi($hr, '/workforce/employees/bulk', ['action' => 'set_department', 'employee_ids' => [$e1->id, $e2->id], 'target_id' => $this->deptB->id])->assertOk();
        $this->assertSame(2, $res->json('updated'));
        $this->assertDatabaseHas('workforce_employees', ['id' => $e1->id, 'department_id' => $this->deptB->id]);
        $this->assertDatabaseHas('workforce_employees', ['id' => $e2->id, 'department_id' => $this->deptB->id]);
    }

    public function test_bulk_set_manager_rejects_cycle(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $boss = $this->emp('Boss');
        $sub = $this->emp('Sub', $boss->id);        // sub managed by boss
        // Tenter de donner `sub` comme responsable de `boss` → cycle.
        $res = $this->postApi($hr, '/workforce/employees/bulk/preview', ['action' => 'set_manager', 'employee_ids' => [$boss->id], 'target_id' => $sub->id])->assertOk();
        $this->assertCount(1, $res->json('ineligible'));
        $this->assertSame(0, count($res->json('eligible')));
    }

    public function test_bulk_set_manager_rejects_self(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $e = $this->emp('Solo');
        $res = $this->postApi($hr, '/workforce/employees/bulk/preview', ['action' => 'set_manager', 'employee_ids' => [$e->id], 'target_id' => $e->id])->assertOk();
        $this->assertCount(1, $res->json('ineligible'));
    }

    public function test_bulk_rejects_cross_company_target(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $e = $this->emp('Un', null, $this->deptA->id);
        $other = Company::create(['name' => 'Other', 'slug' => 'other-bulk', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $foreignDept = Department::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Foreign', 'sort_order' => 0]);

        $this->postApi($hr, '/workforce/employees/bulk', ['action' => 'set_department', 'employee_ids' => [$e->id], 'target_id' => $foreignDept->id])->assertStatus(422);
        $this->assertDatabaseHas('workforce_employees', ['id' => $e->id, 'department_id' => $this->deptA->id]);
    }

    public function test_bulk_terminated_employee_is_ineligible(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $gone = $this->emp('Gone', null, $this->deptA->id, 'terminated');
        $res = $this->postApi($hr, '/workforce/employees/bulk', ['action' => 'set_department', 'employee_ids' => [$gone->id], 'target_id' => $this->deptB->id])->assertOk();
        $this->assertSame(0, $res->json('updated'));
        $this->assertDatabaseHas('workforce_employees', ['id' => $gone->id, 'department_id' => $this->deptA->id]);
    }

    public function test_manager_scope_blocks_out_of_scope_employee(): void
    {
        // hasViewAll('employee') = view && manage → un user manage SANS view n'a PAS la vue globale,
        // son périmètre = soi + rapports directs. L'action groupée exige manage (route) : OK.
        $managerEmp = $this->emp('Mgr', null, $this->deptA->id);
        $report = $this->emp('Report', $managerEmp->id, $this->deptA->id);
        $stranger = $this->emp('Stranger', null, $this->deptA->id);
        $mgr = $this->user(['workforce.manage'], $managerEmp); // manage seul → scopé

        $res = $this->postApi($mgr, '/workforce/employees/bulk', ['action' => 'set_department', 'employee_ids' => [$report->id, $stranger->id], 'target_id' => $this->deptB->id])->assertOk();

        // Le rapport direct est mis à jour, l'inconnu reste hors périmètre.
        $this->assertSame(1, $res->json('updated'));
        $this->assertDatabaseHas('workforce_employees', ['id' => $report->id, 'department_id' => $this->deptB->id]);
        $this->assertDatabaseHas('workforce_employees', ['id' => $stranger->id, 'department_id' => $this->deptA->id]);
    }

    public function test_bulk_requires_manage_permission(): void
    {
        $viewer = $this->user(['workforce.view']);
        $e = $this->emp('Un', null, $this->deptA->id);
        $this->postApi($viewer, '/workforce/employees/bulk', ['action' => 'set_department', 'employee_ids' => [$e->id], 'target_id' => $this->deptB->id])->assertForbidden();
    }

    public function test_bulk_unsupported_action_rejected(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $e = $this->emp('Un', null, $this->deptA->id);
        $this->postApi($hr, '/workforce/employees/bulk', ['action' => 'terminate_all', 'employee_ids' => [$e->id], 'target_id' => $this->deptB->id])->assertStatus(422);
    }
}
