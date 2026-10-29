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
use App\Core\Workforce\JobRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G1 (ADR-641) — garde-fous de suppression Organisation : le backend reste la source de vérité.
 * On ne supprime jamais silencieusement un département avec collaborateurs ou sous-départements,
 * ni un poste avec collaborateurs ; l'erreur métier remonte en 422 (affichée par le dialogue de
 * confirmation). Les suppressions légitimes passent (204). Isolation tenant respectée.
 */
class WorkforceOrganizationDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Org Co', 'slug' => 'org-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);

        $this->admin = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'Admin', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage', 'workforce.admin'])->pluck('id')->toArray());
        Membership::create(['user_id' => $this->admin->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
    }

    private function dept(array $attrs = []): Department
    {
        return Department::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id, 'name' => 'Dept '.uniqid(), 'sort_order' => 0,
        ], $attrs));
    }

    private function role(array $attrs = []): JobRole
    {
        return JobRole::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id, 'title' => 'Role '.uniqid(),
        ], $attrs));
    }

    private function employee(?int $deptId = null, ?int $roleId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => 'Emp', 'last_name' => uniqid(),
            'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01',
            'department_id' => $deptId, 'job_role_id' => $roleId, 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function del(string $path)
    {
        return $this->actingAs($this->admin)->deleteJson('/api'.$path, [], ['X-Company-Id' => $this->company->id]);
    }

    public function test_cannot_delete_department_with_employees(): void
    {
        $dept = $this->dept();
        $this->employee($dept->id);

        $this->del("/workforce/departments/{$dept->id}")->assertStatus(422);
        $this->assertDatabaseHas('workforce_departments', ['id' => $dept->id]);
    }

    public function test_cannot_delete_department_with_sub_departments(): void
    {
        $parent = $this->dept();
        $this->dept(['parent_id' => $parent->id]);

        $this->del("/workforce/departments/{$parent->id}")->assertStatus(422);
        $this->assertDatabaseHas('workforce_departments', ['id' => $parent->id]);
    }

    public function test_can_delete_empty_department(): void
    {
        $dept = $this->dept();
        $this->del("/workforce/departments/{$dept->id}")->assertStatus(204);
        $this->assertDatabaseMissing('workforce_departments', ['id' => $dept->id]);
    }

    public function test_cannot_delete_job_role_with_employees(): void
    {
        $role = $this->role();
        $this->employee(null, $role->id);

        $this->del("/workforce/job-roles/{$role->id}")->assertStatus(422);
        $this->assertDatabaseHas('workforce_job_roles', ['id' => $role->id]);
    }

    public function test_can_delete_unused_job_role(): void
    {
        $role = $this->role();
        $this->del("/workforce/job-roles/{$role->id}")->assertStatus(204);
        $this->assertDatabaseMissing('workforce_job_roles', ['id' => $role->id]);
    }

    public function test_cannot_delete_department_of_another_company(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $foreignDept = Department::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Foreign', 'sort_order' => 0]);

        $this->del("/workforce/departments/{$foreignDept->id}")->assertStatus(404);
        $this->assertDatabaseHas('workforce_departments', ['id' => $foreignDept->id]);
    }
}
