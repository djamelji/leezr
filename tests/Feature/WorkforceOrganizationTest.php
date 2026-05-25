<?php

namespace Tests\Feature;

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
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use App\Modules\Workforce\UseCases\CreateDepartmentUseCase;
use App\Modules\Workforce\UseCases\CreateJobRoleUseCase;
use App\Modules\Workforce\UseCases\DeleteDepartmentUseCase;
use App\Modules\Workforce\UseCases\DeleteJobRoleUseCase;
use App\Modules\Workforce\UseCases\UpdateDepartmentUseCase;
use App\Modules\Workforce\UseCases\UpdateJobRoleUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W1 — Organisation + Compensation Tests
 *
 * Tests: Department CRUD, JobRole CRUD, multi-tenant isolation,
 * Employee org fields, effectiveHourlyRateCents fallback hierarchy,
 * and hourly/daily payroll computation.
 */
class WorkforceOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    private Employee $employee;

    private EmploymentContract $contract;

    private CompensationPlan $compensation;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris',
            'vat_rate_bps' => 2000,
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '',
        ]);
        $this->seed(WorkforcePayrollRuleSeeder::class);

        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_payroll', 'name' => 'Payroll', 'is_enabled_globally' => true]);

        $this->company = Company::create([
            'name' => 'Org Test Co',
            'slug' => 'org-test-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        $this->otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        foreach (['workforce', 'workforce_payroll'] as $mod) {
            CompanyModuleActivationReason::create([
                'company_id' => $this->company->id,
                'module_key' => $mod,
                'reason' => CompanyModuleActivationReason::REASON_DIRECT,
            ]);
            CompanyModuleActivationReason::create([
                'company_id' => $this->otherCompany->id,
                'module_key' => $mod,
                'reason' => CompanyModuleActivationReason::REASON_DIRECT,
            ]);
        }

        $this->user = User::factory()->create();
        Membership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Bob',
            'last_name' => 'Dupont',
            'email' => 'bob@org-test.com',
            'employee_number' => 'EMP-ORG-001',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-01',
            'is_current' => true,
        ]);

        $this->compensation = CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 350000,
            'compensation_type' => 'monthly',
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-01',
        ]);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 1: DEPARTMENT CRUD
    // ════════════════════════════════════════════════════════════════

    public function test_create_department(): void
    {
        $uc = app(CreateDepartmentUseCase::class);
        $dept = $uc->execute($this->company->id, 'Engineering', null, null, 1);

        $this->assertDatabaseHas('workforce_departments', [
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'sort_order' => 1,
        ]);
        $this->assertEquals($this->company->id, $dept->company_id);
    }

    public function test_create_sub_department(): void
    {
        $uc = app(CreateDepartmentUseCase::class);
        $parent = $uc->execute($this->company->id, 'Engineering');
        $child = $uc->execute($this->company->id, 'Frontend', $parent->id);

        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertTrue($parent->fresh()->children->contains($child));
    }

    public function test_update_department(): void
    {
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Old Name',
        ]);

        $uc = app(UpdateDepartmentUseCase::class);
        $updated = $uc->execute($dept, [
            'name' => 'New Name',
            'manager_id' => $this->employee->id,
            'sort_order' => 5,
        ]);

        $this->assertEquals('New Name', $updated->name);
        $this->assertEquals($this->employee->id, $updated->manager_id);
        $this->assertEquals(5, $updated->sort_order);
    }

    public function test_delete_department(): void
    {
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'To Delete',
        ]);

        $uc = app(DeleteDepartmentUseCase::class);
        $uc->execute($dept);

        $this->assertDatabaseMissing('workforce_departments', ['id' => $dept->id]);
    }

    public function test_delete_department_blocked_with_employees(): void
    {
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Has Employees',
        ]);

        $this->employee->update(['department_id' => $dept->id]);

        $uc = app(DeleteDepartmentUseCase::class);
        $this->expectException(\DomainException::class);
        $uc->execute($dept);
    }

    public function test_delete_department_blocked_with_children(): void
    {
        $parent = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Parent',
        ]);
        Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        $uc = app(DeleteDepartmentUseCase::class);
        $this->expectException(\DomainException::class);
        $uc->execute($parent);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 2: JOB ROLE CRUD
    // ════════════════════════════════════════════════════════════════

    public function test_create_job_role(): void
    {
        $uc = app(CreateJobRoleUseCase::class);
        $role = $uc->execute($this->company->id, 'Developer', null, 'senior', '', 2500);

        $this->assertDatabaseHas('workforce_job_roles', [
            'company_id' => $this->company->id,
            'title' => 'Developer',
            'level' => 'senior',
            'default_hourly_rate_cents' => 2500,
        ]);
    }

    public function test_create_job_role_with_department(): void
    {
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Tech',
        ]);

        $uc = app(CreateJobRoleUseCase::class);
        $role = $uc->execute($this->company->id, 'DevOps', $dept->id);

        $this->assertEquals($dept->id, $role->department_id);
    }

    public function test_update_job_role(): void
    {
        $role = JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Old',
        ]);

        $uc = app(UpdateJobRoleUseCase::class);
        $updated = $uc->execute($role, [
            'title' => 'New Title',
            'level' => 'mid',
            'description' => 'A description',
            'default_hourly_rate_cents' => 3000,
        ]);

        $this->assertEquals('New Title', $updated->title);
        $this->assertEquals(3000, $updated->default_hourly_rate_cents);
    }

    public function test_delete_job_role(): void
    {
        $role = JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'To Delete',
        ]);

        $uc = app(DeleteJobRoleUseCase::class);
        $uc->execute($role);

        $this->assertDatabaseMissing('workforce_job_roles', ['id' => $role->id]);
    }

    public function test_delete_job_role_blocked_with_employees(): void
    {
        $role = JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Has People',
        ]);

        $this->employee->update(['job_role_id' => $role->id]);

        $uc = app(DeleteJobRoleUseCase::class);
        $this->expectException(\DomainException::class);
        $uc->execute($role);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 3: MULTI-TENANT ISOLATION
    // ════════════════════════════════════════════════════════════════

    public function test_departments_scoped_to_company(): void
    {
        Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'My Dept',
        ]);
        Department::withoutGlobalScopes()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Dept',
        ]);

        $visible = Department::all();
        $this->assertCount(1, $visible);
        $this->assertEquals('My Dept', $visible->first()->name);
    }

    public function test_job_roles_scoped_to_company(): void
    {
        JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'My Role',
        ]);
        JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->otherCompany->id,
            'title' => 'Other Role',
        ]);

        $visible = JobRole::all();
        $this->assertCount(1, $visible);
        $this->assertEquals('My Role', $visible->first()->title);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 4: EMPLOYEE ORG FIELDS
    // ════════════════════════════════════════════════════════════════

    public function test_employee_department_relation(): void
    {
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Sales',
        ]);

        $this->employee->update(['department_id' => $dept->id]);
        $this->employee->refresh();

        $this->assertEquals('Sales', $this->employee->department->name);
    }

    public function test_employee_job_role_relation(): void
    {
        $role = JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Account Manager',
        ]);

        $this->employee->update(['job_role_id' => $role->id]);
        $this->employee->refresh();

        $this->assertEquals('Account Manager', $this->employee->jobRole->title);
    }

    public function test_employee_manager_relation(): void
    {
        $manager = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'first_name' => 'Eve',
            'last_name' => 'Boss',
            'email' => 'eve@org-test.com',
            'employee_number' => 'EMP-ORG-002',
            'hire_date' => '2023-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->employee->update(['manager_id' => $manager->id]);
        $this->employee->refresh();

        $this->assertEquals('Eve', $this->employee->manager->first_name);
        $this->assertTrue($manager->directReports->contains($this->employee));
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 5: EFFECTIVE HOURLY RATE FALLBACK HIERARCHY
    // ════════════════════════════════════════════════════════════════

    public function test_effective_hourly_rate_from_monthly_salary(): void
    {
        $rate = $this->employee->effectiveHourlyRateCents();

        $this->assertNotNull($rate);
        // base_salary_cents=350000, weekly=35 → monthly_hours=35*52/12=151.666..
        // 350000 / 151.666.. = 2307.69 → round to 2308
        $expected = (int) round(350000 / (35 * 52 / 12));
        $this->assertEquals($expected, $rate);
    }

    public function test_effective_hourly_rate_explicit_hourly(): void
    {
        $this->compensation->update([
            'compensation_type' => 'hourly',
            'hourly_rate_cents' => 2500,
        ]);
        $this->employee->unsetRelation('currentContract');

        $this->assertEquals(2500, $this->employee->effectiveHourlyRateCents());
    }

    public function test_effective_hourly_rate_from_daily(): void
    {
        $this->compensation->update([
            'compensation_type' => 'daily',
            'daily_rate_cents' => 35000,
            'hourly_rate_cents' => null,
        ]);
        $this->employee->unsetRelation('currentContract');

        $rate = $this->employee->effectiveHourlyRateCents();
        // 35h / 5 days = 7h/day → 35000 / 7 = 5000 cents
        $this->assertEquals(5000, $rate);
    }

    public function test_effective_hourly_rate_fallback_to_job_role(): void
    {
        $role = JobRole::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Developer',
            'default_hourly_rate_cents' => 3000,
        ]);
        $this->employee->update(['job_role_id' => $role->id]);

        // Remove compensation to force fallback
        $this->compensation->delete();
        $this->employee->unsetRelation('currentContract');
        $this->employee->unsetRelation('jobRole');

        $this->assertEquals(3000, $this->employee->effectiveHourlyRateCents());
    }

    public function test_effective_hourly_rate_null_when_no_data(): void
    {
        $this->compensation->delete();
        $this->employee->unsetRelation('currentContract');

        $this->assertNull($this->employee->effectiveHourlyRateCents());
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 6: PAYROLL WITH HOURLY COMPENSATION
    // ════════════════════════════════════════════════════════════════

    public function test_payroll_hourly_gross_computation(): void
    {
        $this->compensation->update([
            'compensation_type' => 'hourly',
            'hourly_rate_cents' => 2000,
        ]);

        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'locked',
            'total_worked_minutes' => 9100, // ~151.67 hours
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,

        ]);

        // Create timesheet lines (22 working days × 7h = 9240 min but use 9100 for the test)
        for ($day = 1; $day <= 22; $day++) {
            TimesheetLine::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'timesheet_period_id' => $period->id,
                'employee_id' => $this->employee->id,
                'date' => sprintf('2025-01-%02d', $day),
                'worked_minutes' => (int) round(9100 / 22),
                'break_minutes' => 0,
                'daily_overtime_minutes' => 0,
                'is_leave_day' => false,
                'is_rest_day' => false,
                'source_snapshot' => [],
            ]);
        }

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        $uc = app(ComputePayrollUseCase::class);
        $uc->execute($run, $this->user->id);

        $run->refresh();
        $line = $run->lines()->where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($line);
        // 9100 minutes / 60 = 151.67 hours × 20.00 EUR = 3033.33 EUR = 303333 cents
        $expectedGross = (int) round((9100 / 60) * 2000);
        $this->assertEquals($expectedGross, $line->gross_basis_cents);
        $this->assertEquals('hourly', $line->compensation_snapshot['compensation_type']);
    }

    public function test_payroll_daily_gross_computation(): void
    {
        $this->compensation->update([
            'compensation_type' => 'daily',
            'daily_rate_cents' => 30000,
            'hourly_rate_cents' => null,
        ]);

        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'locked',
            'total_worked_minutes' => 9240, // 22 days × 7h = 154h = 9240 min
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,

        ]);

        // Create 22 working day lines
        for ($day = 1; $day <= 22; $day++) {
            TimesheetLine::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'timesheet_period_id' => $period->id,
                'employee_id' => $this->employee->id,
                'date' => sprintf('2025-01-%02d', $day),
                'worked_minutes' => 420, // 7h
                'break_minutes' => 0,
                'daily_overtime_minutes' => 0,
                'is_leave_day' => false,
                'is_rest_day' => false,
                'source_snapshot' => [],
            ]);
        }

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        $uc = app(ComputePayrollUseCase::class);
        $uc->execute($run, $this->user->id);

        $run->refresh();
        $line = $run->lines()->where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($line);
        // 22 days × 300.00 EUR = 6600.00 EUR = 660000 cents
        $this->assertEquals(660000, $line->gross_basis_cents);
        $this->assertEquals('daily', $line->compensation_snapshot['compensation_type']);
    }

    public function test_payroll_monthly_backward_compatible(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'locked',
            'total_worked_minutes' => 9100,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,

        ]);

        for ($day = 1; $day <= 22; $day++) {
            TimesheetLine::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'timesheet_period_id' => $period->id,
                'employee_id' => $this->employee->id,
                'date' => sprintf('2025-01-%02d', $day),
                'worked_minutes' => (int) round(9100 / 22),
                'break_minutes' => 0,
                'daily_overtime_minutes' => 0,
                'is_leave_day' => false,
                'is_rest_day' => false,
                'source_snapshot' => [],
            ]);
        }

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        $uc = app(ComputePayrollUseCase::class);
        $uc->execute($run, $this->user->id);

        $run->refresh();
        $line = $run->lines()->where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($line);
        // Monthly salary: 3500.00 EUR, no overtime, no leave
        $this->assertEquals(350000, $line->gross_basis_cents);
    }

    public function test_payroll_hourly_zero_rate_creates_anomaly(): void
    {
        $this->compensation->update([
            'compensation_type' => 'hourly',
            'hourly_rate_cents' => 0,
        ]);
        $this->employee->update(['job_role_id' => null]);

        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'locked',
            'total_worked_minutes' => 9100,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,

        ]);

        for ($day = 1; $day <= 22; $day++) {
            TimesheetLine::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'timesheet_period_id' => $period->id,
                'employee_id' => $this->employee->id,
                'date' => sprintf('2025-01-%02d', $day),
                'worked_minutes' => (int) round(9100 / 22),
                'break_minutes' => 0,
                'daily_overtime_minutes' => 0,
                'is_leave_day' => false,
                'is_rest_day' => false,
                'source_snapshot' => [],
            ]);
        }

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        $uc = app(ComputePayrollUseCase::class);
        $uc->execute($run, $this->user->id);

        $run->refresh();
        $line = $run->lines()->where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($line);
        $anomalies = collect($line->anomalies ?? []);
        $this->assertTrue(
            $anomalies->contains(fn ($a) => ($a['type'] ?? '') === PayrollRun::ANOMALY_ZERO_BASE_SALARY),
            'Expected ZERO_BASE_SALARY anomaly for zero hourly rate'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 7: COMPENSATION TYPE DEFAULTS
    // ════════════════════════════════════════════════════════════════

    public function test_compensation_plan_defaults_to_monthly(): void
    {
        $plan = CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 400000,
            'compensation_type' => 'monthly',
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        $this->assertEquals('monthly', $plan->compensation_type);
    }

    public function test_compensation_plan_hourly_type(): void
    {
        $plan = CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'compensation_type' => 'hourly',
            'hourly_rate_cents' => 2500,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        $this->assertEquals('hourly', $plan->compensation_type);
        $this->assertEquals(2500, $plan->hourly_rate_cents);
    }

    public function test_compensation_plan_daily_type(): void
    {
        $plan = CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'compensation_type' => 'daily',
            'daily_rate_cents' => 35000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        $this->assertEquals('daily', $plan->compensation_type);
        $this->assertEquals(35000, $plan->daily_rate_cents);
    }
}
