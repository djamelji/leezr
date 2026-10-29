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
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-640 (W-C) — fiche collaborateur unifiée : agrégat composite, scope manager (W-D), masquage salaire,
 * sections conditionnées par modules actifs, isolation tenant.
 */
class WorkforceEmployeeFileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'WF', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_leave', 'name' => 'Leave', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'F Co', 'slug' => 'f-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->manager = $this->emp('Mona', 'M', null);
        $this->report = $this->emp('Remy', 'R', $this->manager->id);
        $this->stranger = $this->emp('Sam', 'S', null);

        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);
        CompensationPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'contract_id' => $contract->id, 'base_salary_cents' => 450000, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'overtime_rate_bps' => 2500, 'effective_from' => '2026-01-01', 'compensation_type' => 'monthly']);
    }

    private function emp(string $f, string $l, ?int $mgr): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $f, 'last_name' => $l, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid()]);
    }

    private function user(array $perms, bool $admin, ?Employee $as = null): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($as) {
            $as->update(['user_id' => $u->id]);
        }

        return $u;
    }

    private function getFile(User $u, int $id)
    {
        return $this->actingAs($u)->getJson("/api/workforce/employees/{$id}/file", ['X-Company-Id' => $this->company->id]);
    }

    public function test_hr_sees_full_file_with_sections(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage'], true);
        $res = $this->getFile($hr, $this->report->id)->assertOk()->json();
        $this->assertSame('Remy', $res['identity']['first_name']);
        $this->assertSame('cdi', $res['contract']['contract_type']);
        $this->assertContains('leave', $res['available_sections']);
    }

    public function test_salary_hidden_without_compensation_read(): void
    {
        $noComp = $this->user(['workforce.view', 'workforce.manage'], true);
        $res = $this->getFile($noComp, $this->report->id);
        $this->assertStringNotContainsString('base_salary_cents', $res->getContent());

        $hr = $this->user(['workforce.view', 'workforce.manage', 'workforce.compensation_read'], true);
        $this->assertStringContainsString('base_salary_cents', $this->getFile($hr, $this->report->id)->getContent());
    }

    public function test_manager_scope_report_yes_stranger_no(): void
    {
        $mgr = $this->user(['workforce.leave_request'], false, $this->manager);
        $this->getFile($mgr, $this->report->id)->assertOk();     // rapport direct
        $this->getFile($mgr, $this->stranger->id)->assertForbidden(); // inconnu
    }

    public function test_self_sees_own_file(): void
    {
        $emp = $this->user(['workforce.leave_request'], false, $this->stranger);
        $this->getFile($emp, $this->stranger->id)->assertOk();
    }

    public function test_inactive_module_hides_section(): void
    {
        // Désactiver workforce_leave pour cette company.
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce_leave')->delete();
        \App\Core\Modules\ModuleRegistry::clearCache();
        $hr = $this->user(['workforce.view', 'workforce.manage'], true);
        $res = $this->getFile($hr, $this->report->id)->assertOk()->json();
        $this->assertNotContains('leave', $res['available_sections']);
        $this->assertNull($res['leave']);
    }

    public function test_missing_and_cross_tenant(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage'], true);
        $this->getFile($hr, 999999)->assertNotFound();

        $other = Company::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $ou = User::factory()->create();
        Membership::create(['user_id' => $ou->id, 'company_id' => $other->id, 'role' => 'owner']);
        $this->actingAs($ou)->getJson("/api/workforce/employees/{$this->report->id}/file", ['X-Company-Id' => $other->id])
            ->assertStatus(403); // module non actif sur l'autre company → gate
    }
}
