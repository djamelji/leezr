<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ClockInUseCase;
use App\Modules\Workforce\UseCases\ClockOutUseCase;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use App\Modules\Workforce\UseCases\EndBreakUseCase;
use App\Modules\Workforce\UseCases\RequestLeaveUseCase;
use App\Modules\Workforce\UseCases\StartBreakUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workforce Functional Smoke Tests
 *
 * Proves the PRODUCT works end-to-end, not just individual classes.
 * Covers: employee self-service, RH validation + payroll cycle,
 * multi-tenant security, and payroll safety anomalies.
 */
class WorkforceFunctionalSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $employee;

    private EmploymentContract $contract;

    private CompensationPlan $compensation;

    private LeaveType $leaveTypePaid;

    private LeaveType $leaveTypeUnpaid;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        // ── Market + Payroll Rules ──
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

        // ── Platform Modules ──
        PlatformModule::create([
            'key' => 'workforce',
            'name' => 'Workforce',
            'is_enabled_globally' => true,
        ]);
        PlatformModule::create([
            'key' => 'workforce_payroll',
            'name' => 'Payroll',
            'is_enabled_globally' => true,
        ]);

        // ── Company ──
        $this->company = Company::create([
            'name' => 'Smoke Test Co',
            'slug' => 'smoke-test-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        // Activate modules for company
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id,
            'module_key' => 'workforce',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id,
            'module_key' => 'workforce_payroll',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);

        // ── User (both member and employee) ──
        $this->user = User::factory()->create([
            'name' => 'Alice Martin',
            'email' => 'alice@smoketest.com',
        ]);

        Membership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        // ── Employee ──
        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Alice',
            'last_name' => 'Martin',
            'email' => 'alice@smoketest.com',
            'employee_number' => 'EMP-SMOKE-001',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        // ── Employment Contract ──
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

        // ── Compensation Plan ──
        $this->compensation = CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 350000, // 3500.00 EUR
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500, // 25%
            'effective_from' => '2024-01-01',
        ]);

        // ── Leave Types ──
        $this->leaveTypePaid = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'CP',
            'name' => 'Congés payés',
            'accrual_mode' => 'monthly',
            'annual_entitlement_hundredths' => 2500,
            'is_paid' => true,
            'enabled' => true,
        ]);

        $this->leaveTypeUnpaid = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'CSS',
            'name' => 'Congé sans solde',
            'accrual_mode' => 'none',
            'annual_entitlement_hundredths' => 0,
            'is_paid' => false,
            'enabled' => true,
        ]);

        // Bind company context for CompanyScope
        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 1: EMPLOYEE SELF-SERVICE
    // ════════════════════════════════════════════════════════════════

    /** @test */
    public function employee_can_view_own_profile(): void
    {
        // The employee record linked to the user should be retrievable
        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($employee);
        $this->assertEquals($this->employee->id, $employee->id);
        $this->assertEquals('Alice', $employee->first_name);
        $this->assertEquals('Martin', $employee->last_name);
        $this->assertEquals('EMP-SMOKE-001', $employee->employee_number);
        $this->assertEquals(Employee::STATUS_ACTIVE, $employee->status);
    }

    /** @test */
    public function employee_can_clock_in_and_out(): void
    {
        // Clock in
        $entry = app(ClockInUseCase::class)->execute(
            employee: $this->employee,
            source: 'manual',
        );

        $this->assertNotNull($entry->id);
        $this->assertEquals(TimeEntry::STATUS_WORKING, $entry->status);
        $this->assertNotNull($entry->clock_in);
        $this->assertNull($entry->clock_out);

        // Clock out
        $entry = app(ClockOutUseCase::class)->execute($entry);

        $this->assertEquals(TimeEntry::STATUS_COMPLETED, $entry->status);
        $this->assertNotNull($entry->clock_out);
        $this->assertGreaterThanOrEqual(0, $entry->total_worked_minutes);
    }

    /** @test */
    public function employee_can_start_and_end_break(): void
    {
        // Clock in
        $entry = app(ClockInUseCase::class)->execute(
            employee: $this->employee,
            source: 'manual',
        );
        $this->assertEquals(TimeEntry::STATUS_WORKING, $entry->status);

        // Start break
        $break = app(StartBreakUseCase::class)->execute(
            entry: $entry,
            type: 'lunch',
        );
        $entry->refresh();

        $this->assertNotNull($break->id);
        $this->assertEquals(TimeEntry::STATUS_ON_BREAK, $entry->status);
        $this->assertNotNull($break->start_at);
        $this->assertNull($break->end_at);

        // End break
        $breakEnded = app(EndBreakUseCase::class)->execute($entry);
        $entry->refresh();

        $this->assertEquals(TimeEntry::STATUS_WORKING, $entry->status);
        $this->assertNotNull($breakEnded->end_at);

        // Clock out
        $entry = app(ClockOutUseCase::class)->execute($entry);
        $this->assertEquals(TimeEntry::STATUS_COMPLETED, $entry->status);
    }

    /** @test */
    public function employee_cannot_clock_in_twice(): void
    {
        // First clock in succeeds
        app(ClockInUseCase::class)->execute(
            employee: $this->employee,
            source: 'manual',
        );

        // Second clock in should fail
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already has an active time entry');

        app(ClockInUseCase::class)->execute(
            employee: $this->employee,
            source: 'manual',
        );
    }

    /** @test */
    public function employee_can_request_leave(): void
    {
        $leaveRequest = app(RequestLeaveUseCase::class)->execute(
            employee: $this->employee,
            leaveTypeId: $this->leaveTypePaid->id,
            dateFrom: '2026-07-01',
            dateTo: '2026-07-05',
            daysCountHundredths: 500, // 5 days
            reason: 'Vacances été',
            allowNegative: true, // No balance seeded for this test
        );

        $this->assertNotNull($leaveRequest->id);
        $this->assertEquals($this->employee->id, $leaveRequest->employee_id);
        $this->assertEquals($this->leaveTypePaid->id, $leaveRequest->leave_type_id);
        $this->assertEquals(500, $leaveRequest->days_count_hundredths);

        // Verify the leave request is stored and queryable
        $found = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->first();

        $this->assertNotNull($found);
        $this->assertEquals($leaveRequest->id, $found->id);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 2: RH VALIDATION → PAYROLL CYCLE
    // ════════════════════════════════════════════════════════════════

    /** @test */
    public function full_payroll_cycle_with_validated_hours(): void
    {
        // 1. Create a completed time entry (7h worked)
        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-06-02',
            'clock_in' => '2026-06-02 08:00:00',
            'clock_out' => '2026-06-02 15:00:00',
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 420, // 7h
            'total_break_minutes' => 0,
        ]);
        $this->assertNotNull($entry->id);

        // 2. Create locked timesheet for the period
        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100, // ~151.67h (standard month)
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // 3. Create payroll run in draft status
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'idempotency_key' => 'payroll:smoke:' . uniqid(),
        ]);

        // 4. Compute payroll
        $computedRun = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        // 5. Verify run transitioned to computed
        $this->assertEquals(PayrollRun::STATUS_COMPUTED, $computedRun->status);
        $this->assertEquals(1, $computedRun->employee_count);
        $this->assertNotNull($computedRun->computed_at);
        $this->assertEquals($this->user->id, $computedRun->computed_by);

        // 6. Verify payroll line was created with correct data
        $lines = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $computedRun->id)
            ->get();

        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertEquals($this->employee->id, $line->employee_id);
        $this->assertEquals($timesheet->id, $line->timesheet_period_id);
        $this->assertEquals(350000, $line->base_salary_cents); // 3500 EUR
        $this->assertGreaterThan(0, $line->gross_basis_cents);

        // 7. Verify gross_breakdown is populated
        $breakdown = $line->gross_breakdown;
        $this->assertIsArray($breakdown);
        $this->assertEquals(350000, $breakdown['base_salary_cents']);
        $this->assertArrayHasKey('hourly_rate_cents', $breakdown);
        $this->assertArrayHasKey('formula_version', $breakdown);

        // 8. Verify compensation_snapshot
        $compSnapshot = $line->compensation_snapshot;
        $this->assertIsArray($compSnapshot);
        $this->assertEquals(350000, $compSnapshot['base_salary_cents']);
        $this->assertEquals('EUR', $compSnapshot['currency']);
        $this->assertEquals('monthly', $compSnapshot['pay_frequency']);

        // 9. Verify timesheet_snapshot
        $tsSnapshot = $line->timesheet_snapshot;
        $this->assertIsArray($tsSnapshot);
        $this->assertEquals(9100, $tsSnapshot['total_worked_minutes']);
        $this->assertEquals(TimesheetPeriod::STATUS_LOCKED, $tsSnapshot['status']);

        // 10. Total gross should match
        $this->assertEquals($line->gross_basis_cents, $computedRun->total_gross_cents);
    }

    /** @test */
    public function payroll_compute_detects_missing_timesheet(): void
    {
        // No timesheet created for this employee/period

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'idempotency_key' => 'payroll:smoke:no-ts:' . uniqid(),
        ]);

        $computedRun = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        // Run should be computed but with anomalies
        $this->assertEquals(PayrollRun::STATUS_COMPUTED, $computedRun->status);
        $this->assertGreaterThan(0, $computedRun->anomaly_count);

        $anomalies = $computedRun->anomalies;
        $this->assertNotEmpty($anomalies);

        $types = array_column($anomalies, 'type');
        $this->assertContains(PayrollRun::ANOMALY_MISSING_TIMESHEET, $types);

        // No payroll lines should be created for this employee
        $lines = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $computedRun->id)
            ->get();
        $this->assertCount(0, $lines);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 3: SECURITY — MULTI-TENANT ISOLATION
    // ════════════════════════════════════════════════════════════════

    /** @test */
    public function employee_cannot_see_other_company_employees(): void
    {
        // Create a second company with its own employee
        $companyB = Company::create([
            'name' => 'Other Corp',
            'slug' => 'other-corp',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
        ]);

        $userB = User::factory()->create(['email' => 'bob@other.com']);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'email' => 'bob@other.com',
            'employee_number' => 'EMP-OTHER-001',
            'hire_date' => '2024-06-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        // With company A context, should only see company A employees
        app()->instance('company.context', $this->company);
        $employees = Employee::all();

        $this->assertCount(1, $employees);
        $this->assertEquals($this->employee->id, $employees->first()->id);

        // Should NOT contain company B employee
        $this->assertFalse($employees->contains('id', $employeeB->id));
    }

    /** @test */
    public function clock_in_creates_entry_for_correct_employee(): void
    {
        // Create a second employee in a different company
        $companyB = Company::create([
            'name' => 'Rival Co',
            'slug' => 'rival-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
        ]);

        $userB = User::factory()->create(['email' => 'charlie@rival.com']);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'first_name' => 'Charlie',
            'last_name' => 'Dupont',
            'email' => 'charlie@rival.com',
            'employee_number' => 'EMP-RIVAL-001',
            'hire_date' => '2024-06-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        // Clock in employee A
        $entry = app(ClockInUseCase::class)->execute(
            employee: $this->employee,
            source: 'manual',
        );

        // The time entry belongs to company A, not B
        $this->assertEquals($this->company->id, $entry->company_id);
        $this->assertEquals($this->employee->id, $entry->employee_id);

        // No time entries should exist for employee B
        $entriesB = TimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employeeB->id)
            ->count();

        $this->assertEquals(0, $entriesB);
    }

    /** @test */
    public function time_entries_are_scoped_to_company(): void
    {
        // Create entries in two companies
        $companyB = Company::create([
            'name' => 'Corp B',
            'slug' => 'corp-b',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
        ]);

        $userB = User::factory()->create(['email' => 'dan@corpb.com']);
        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'first_name' => 'Dan',
            'last_name' => 'Smith',
            'email' => 'dan@corpb.com',
            'employee_number' => 'EMP-CORPB-001',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        // Entry in company A
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'clock_in' => now()->subHours(2),
            'clock_out' => now(),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 120,
        ]);

        // Entry in company B
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'employee_id' => $employeeB->id,
            'date' => now()->toDateString(),
            'clock_in' => now()->subHours(3),
            'clock_out' => now(),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 180,
        ]);

        // Scoped query should return only company A entries
        app()->instance('company.context', $this->company);
        $entries = TimeEntry::all();

        $this->assertCount(1, $entries);
        $this->assertEquals($this->company->id, $entries->first()->company_id);

        app()->forgetInstance('company.context');
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 4: PAYROLL SAFETY ANOMALIES
    // ════════════════════════════════════════════════════════════════

    /** @test */
    public function zero_salary_creates_anomaly(): void
    {
        // Create employee with zero salary
        $zeroSalaryEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => null,
            'first_name' => 'Zero',
            'last_name' => 'Salary',
            'email' => 'zero@smoketest.com',
            'employee_number' => 'EMP-ZERO-SAL',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $zeroContract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $zeroSalaryEmployee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-01',
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $zeroContract->id,
            'base_salary_cents' => 0, // ZERO salary
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-01',
        ]);

        // Create locked timesheet with hours
        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $zeroSalaryEmployee->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // Also create timesheet for main employee to avoid noise
        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // Create and compute payroll run
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'idempotency_key' => 'payroll:smoke:zero-sal:' . uniqid(),
        ]);

        $computedRun = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        // Find the payroll line for the zero-salary employee
        $zeroLine = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $computedRun->id)
            ->where('employee_id', $zeroSalaryEmployee->id)
            ->first();

        $this->assertNotNull($zeroLine, 'Payroll line should exist for zero-salary employee');
        $this->assertNotNull($zeroLine->anomalies, 'Anomalies should be set');

        $anomalyTypes = array_column($zeroLine->anomalies, 'type');
        $this->assertContains(
            PayrollRun::ANOMALY_ZERO_BASE_SALARY,
            $anomalyTypes,
            'Should contain zero_base_salary anomaly'
        );

        // Verify severity is error
        $zeroSalaryAnomaly = collect($zeroLine->anomalies)
            ->firstWhere('type', PayrollRun::ANOMALY_ZERO_BASE_SALARY);
        $this->assertEquals('error', $zeroSalaryAnomaly['severity']);
    }

    /** @test */
    public function zero_weekly_hours_creates_anomaly(): void
    {
        // Create employee with zero weekly hours
        $zeroHoursEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => null,
            'first_name' => 'Zero',
            'last_name' => 'Hours',
            'email' => 'zerohours@smoketest.com',
            'employee_number' => 'EMP-ZERO-HRS',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $zeroContract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $zeroHoursEmployee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 0, // ZERO hours
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-01',
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $zeroContract->id,
            'base_salary_cents' => 350000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-01',
        ]);

        // Create locked timesheet
        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $zeroHoursEmployee->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // Also create timesheet for main employee
        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // Create and compute payroll run
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'idempotency_key' => 'payroll:smoke:zero-hrs:' . uniqid(),
        ]);

        $computedRun = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        // Find the payroll line for the zero-hours employee
        $zeroLine = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $computedRun->id)
            ->where('employee_id', $zeroHoursEmployee->id)
            ->first();

        $this->assertNotNull($zeroLine, 'Payroll line should exist for zero-hours employee');
        $this->assertNotNull($zeroLine->anomalies, 'Anomalies should be set');

        $anomalyTypes = array_column($zeroLine->anomalies, 'type');
        $this->assertContains(
            PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS,
            $anomalyTypes,
            'Should contain invalid_weekly_hours anomaly'
        );

        // Verify severity is error
        $zeroHoursAnomaly = collect($zeroLine->anomalies)
            ->firstWhere('type', PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS);
        $this->assertEquals('error', $zeroHoursAnomaly['severity']);
    }

    /** @test */
    public function normal_salary_does_not_create_zero_salary_anomaly(): void
    {
        // Use the standard setup (salary = 3500 EUR, weekly_hours = 35)
        // to prove anomaly detection does NOT false-positive

        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'idempotency_key' => 'payroll:smoke:normal:' . uniqid(),
        ]);

        $computedRun = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        $line = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $computedRun->id)
            ->where('employee_id', $this->employee->id)
            ->first();

        $this->assertNotNull($line);

        // Line should have NO zero-salary or zero-hours anomalies
        $anomalies = $line->anomalies ?? [];
        $anomalyTypes = array_column($anomalies, 'type');

        $this->assertNotContains(
            PayrollRun::ANOMALY_ZERO_BASE_SALARY,
            $anomalyTypes,
            'Normal salary should NOT trigger zero_base_salary anomaly'
        );
        $this->assertNotContains(
            PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS,
            $anomalyTypes,
            'Normal weekly hours should NOT trigger invalid_weekly_hours anomaly'
        );

        // Gross basis should be > 0
        $this->assertGreaterThan(0, $line->gross_basis_cents);
    }

    // ════════════════════════════════════════════════════════════════
    // SCENARIO 5: ANOMALY CONSTANTS INTEGRITY
    // ════════════════════════════════════════════════════════════════

    /** @test */
    public function anomaly_constants_are_registered_in_severities_map(): void
    {
        // Every ANOMALY_* string constant must have a corresponding severity
        $reflection = new \ReflectionClass(PayrollRun::class);
        $constants = $reflection->getConstants();

        $anomalyConstants = array_filter(
            $constants,
            fn ($value, $key) => str_starts_with($key, 'ANOMALY_') && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertNotEmpty($anomalyConstants, 'Should have at least one ANOMALY_* string constant');

        foreach ($anomalyConstants as $name => $value) {
            $this->assertArrayHasKey(
                $value,
                PayrollRun::ANOMALY_SEVERITIES,
                "Anomaly constant {$name} = '{$value}' must have a severity in ANOMALY_SEVERITIES"
            );
        }
    }

    /** @test */
    public function new_anomaly_constants_exist(): void
    {
        $this->assertEquals('zero_base_salary', PayrollRun::ANOMALY_ZERO_BASE_SALARY);
        $this->assertEquals('invalid_weekly_hours', PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS);

        $this->assertEquals('error', PayrollRun::ANOMALY_SEVERITIES[PayrollRun::ANOMALY_ZERO_BASE_SALARY]);
        $this->assertEquals('error', PayrollRun::ANOMALY_SEVERITIES[PayrollRun::ANOMALY_INVALID_WEEKLY_HOURS]);
    }
}
