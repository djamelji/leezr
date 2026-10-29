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
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\JobRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G2 (ADR-642) — liste filtrée serveur (département/poste/manager/contrat), export CSV respectant
 * filtres + scope manager, aucune donnée sensible (ni salaire ni IBAN) dans l'export. RBAC export.
 */
class WorkforceEmployeeListExportTest extends TestCase
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

        $this->company = Company::create(['name' => 'List Co', 'slug' => 'list-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);

        $this->deptA = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Alpha', 'sort_order' => 0]);
        $this->deptB = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Beta', 'sort_order' => 1]);
    }

    private function emp(string $first, ?int $deptId = null, ?int $managerId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => $first, 'last_name' => 'Doe',
            'status' => 'active', 'hire_date' => '2026-01-01', 'department_id' => $deptId,
            'manager_id' => $managerId, 'employee_number' => 'E-'.strtoupper(substr(md5($first), 0, 6)),
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

    private function getApi(User $u, string $path)
    {
        return $this->actingAs($u)->get('/api'.$path, ['X-Company-Id' => $this->company->id]);
    }

    public function test_list_filters_by_department(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $a = $this->emp('Anna', $this->deptA->id);
        $this->emp('Bob', $this->deptB->id);

        $res = $this->actingAs($hr)->getJson('/api/workforce/employees?department_id='.$this->deptA->id, ['X-Company-Id' => $this->company->id])->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_export_csv_has_no_salary_or_sensitive_columns(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage', 'workforce.export', 'workforce.compensation_read']);
        $e = $this->emp('Anna', $this->deptA->id);
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        CompensationPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'contract_id' => $contract->id, 'base_salary_cents' => 777777, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'overtime_rate_bps' => 2500, 'effective_from' => '2026-01-01', 'compensation_type' => 'monthly']);

        $res = $this->getApi($hr, '/workforce/employees/export')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('Anna', $csv);
        $this->assertStringContainsString('contract_type', $csv); // en-tête présent
        $this->assertStringContainsString('cdi', $csv);
        $this->assertStringNotContainsString('777777', $csv, 'aucun salaire dans l’export (même avec compensation_read)');
        $this->assertStringNotContainsString('base_salary', $csv);
        $this->assertStringNotContainsString('iban', strtolower($csv));
    }

    public function test_export_respects_manager_scope(): void
    {
        $managerEmp = $this->emp('Mgr', $this->deptA->id);
        $report = $this->emp('Report', $this->deptA->id, $managerEmp->id);
        $stranger = $this->emp('Stranger', $this->deptA->id);
        $mgr = $this->user(['workforce.export'], $managerEmp); // export sans vue globale → scopé

        $csv = $this->getApi($mgr, '/workforce/employees/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Mgr', $csv);
        $this->assertStringContainsString('Report', $csv);
        $this->assertStringNotContainsString('Stranger', $csv, 'un manager n’exporte pas hors de son périmètre');
    }

    public function test_export_requires_export_permission(): void
    {
        $viewer = $this->user(['workforce.view']); // pas workforce.export
        $this->getApi($viewer, '/workforce/employees/export')->assertForbidden();
    }

    public function test_import_template_requires_import_permission(): void
    {
        $viewer = $this->user(['workforce.view']);
        $this->getApi($viewer, '/workforce/employees/import/template')->assertForbidden();

        $importer = $this->user(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->getApi($importer, '/workforce/employees/import/template')->assertOk()->streamedContent();
        $this->assertStringContainsString('first_name', $csv);
    }
}
