<?php

namespace Tests\Feature;

use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmployeeTaxProfile;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Services\PayrollRuleResolver;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\PreviewPayrollCalculationUseCase;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use App\Core\Markets\Market;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCalculationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private PayrollRun $run;
    private PayrollLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        // Create FR market (needed for MarketRuleSet FK)
        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR',
            'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000,
            'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '',
        ]);

        // Seed FR contribution rules
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $this->company = Company::create([
            'name' => 'Test Payroll Co',
            'slug' => 'test-payroll-co',
            'jobdomain_key' => 'logistique',
        ]);

        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'jean@test.com',
            'employee_number' => 'EMP-001',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'base_salary_cents' => 350000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        // Create a computed PayrollRun with a line
        $this->run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 350000,
        ]);

        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
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

        $this->line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $timesheet->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 350000,
            'gross_breakdown' => [
                'base_salary_cents' => 350000,
                'overtime_cents' => 0,
                'unpaid_leave_deduction_cents' => 0,
                'gross_basis_cents' => 350000,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 350000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'benefits' => [],
            ],
            'timesheet_snapshot' => [
                'total_worked_minutes' => 9100,
            ],
        ]);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ── T17: ComputePayrollCalculations — happy path ──

    public function test_compute_calculations_happy_path(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertNotNull($calc);
        $this->assertEquals('calculated', $calc->status);
        $this->assertEquals(\App\Core\Workforce\PayrollCalculationEngine::CALC_VERSION, $calc->rule_version);
        $this->assertGreaterThan(0, $calc->gross_total_cents);
        $this->assertGreaterThan(0, $calc->contributions_employee_cents);
        $this->assertGreaterThan(0, $calc->net_payable_cents);
        $this->assertNotNull($calc->calculation_snapshot);
    }

    // ── T18: ComputePayrollCalculations — guard status ──

    public function test_compute_calculations_blocks_non_computed(): void
    {
        $this->run->status = PayrollRun::STATUS_DRAFT;
        $this->run->saveQuietly();

        $useCase = app(ComputePayrollCalculationsUseCase::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expected \'computed\'');
        $useCase->execute($this->run, $this->user->id);
    }

    // ── T19: Recalcul idempotent ──

    public function test_recalculation_is_idempotent(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);

        $useCase->execute($this->run, $this->user->id);
        $firstCalc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();
        $firstId = $firstCalc->id;

        // Recalculate
        $useCase->execute($this->run, $this->user->id);
        $secondCalc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        // Old one deleted, new one created
        $this->assertNotEquals($firstId, $secondCalc->id);
        // Only 1 calculation exists per line
        $count = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    // ── T20: Recalcul impossible après validation ──

    public function test_recalculation_blocked_after_validation(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        // Validate the run
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($this->run->fresh(), $this->user->id);

        // Try to recalculate — should fail because run is now validated
        $this->expectException(\DomainException::class);
        $useCase->execute($this->run->fresh(), $this->user->id);
    }

    // ── T21: Validation bloquée si lignes sans calcul ──

    public function test_validation_blocked_without_calculations(): void
    {
        // Run has lines but no calculations
        $validateUseCase = new ValidatePayrollUseCase();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('have no calculation');
        $validateUseCase->execute($this->run, $this->user->id);
    }

    // ── T22: Validation bloquée si blocking_anomalies ──

    public function test_validation_blocked_with_blocking_anomalies(): void
    {
        // Create calculation with blocking anomaly
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $this->line->id,
            'rule_version' => 'payroll-calc-v1',
            'plafond_ss_monthly_cents' => 383400,
            'gross_total_cents' => 350000,
            'contributions_employee_cents' => 78750,
            'contributions_employer_cents' => 148400,
            'total_cost_employer_cents' => 498400,
            'taxable_income_cents' => 272080,
            'tax_cents' => 29928,
            'net_before_tax_cents' => 271250,
            'net_payable_cents' => 241322,
            'contribution_lines' => [],
            'tax_breakdown' => [],
            'blocking_anomalies' => [['type' => 'negative_net', 'severity' => 'blocking', 'message' => 'Test']],
            'calculation_snapshot' => ['test' => true],
            'status' => 'calculated',
            'calculated_at' => now(),
        ]);

        $validateUseCase = new ValidatePayrollUseCase();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blocking anomalies');
        $validateUseCase->execute($this->run, $this->user->id);
    }

    // ── T23: Validation marque status='validated' ──

    public function test_validation_marks_calculations_validated(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($this->run->fresh(), $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertEquals('validated', $calc->status);
    }

    // ── T24: Snapshot immutabilité après validation ──

    public function test_snapshot_immutable_after_validation(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($this->run->fresh(), $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->expectException(\RuntimeException::class);
        $calc->update(['calculation_snapshot' => ['modified' => true]]);
    }

    // ── T25: Preview ne crée aucune DB row ni audit ──

    public function test_preview_creates_no_db_rows(): void
    {
        $countBefore = PayrollCalculation::withoutGlobalScopes()->count();

        $previewUseCase = app(PreviewPayrollCalculationUseCase::class);
        $result = $previewUseCase->execute($this->line);

        $countAfter = PayrollCalculation::withoutGlobalScopes()->count();

        $this->assertEquals($countBefore, $countAfter);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('gross_total_cents', $result);
        $this->assertArrayHasKey('net_payable_cents', $result);
    }

    // ── T26: RuleResolver — FR rules loaded ──

    public function test_rule_resolver_loads_fr_rules(): void
    {
        // Need market_key set for rule resolution
        $this->company->update(['market_key' => 'FR']);

        $resolver = new PayrollRuleResolver();
        $rules = $resolver->resolveContributions('FR', now());

        $this->assertGreaterThanOrEqual(9, count($rules));

        $codes = array_column($rules, 'code');
        $this->assertContains('urssaf_maladie', $codes);
        $this->assertContains('csg_deductible', $codes);
        $this->assertContains('csg_non_deductible', $codes);
    }

    // ── T27: RuleResolver — plafond SS correct 2026 ──

    public function test_rule_resolver_plafond_ss_2026(): void
    {
        $resolver = new PayrollRuleResolver();
        $plafond = $resolver->resolvePlafondSS('FR', now());

        $this->assertEquals(383400, $plafond);
    }

    // ── T28: Multi-tenant — calculs isolés ──

    public function test_multi_tenant_calculations_isolated(): void
    {
        $companyB = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'logistique',
        ]);

        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        // Set context to company B
        app()->instance('company.context', $companyB);

        $calcsB = PayrollCalculation::query()->get();
        $this->assertCount(0, $calcsB);

        // Set context back to company A
        app()->instance('company.context', $this->company);
        $calcsA = PayrollCalculation::query()->get();
        $this->assertCount(1, $calcsA);
    }

    // ── T29: ReadModel — runTotals cohérents ──

    public function test_readmodel_run_totals_consistent(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $totals = \App\Modules\Workforce\ReadModels\PayrollCalculationReadModel::runTotals($this->run->id);

        $this->assertEquals(1, $totals['count']);
        $this->assertGreaterThan(0, $totals['gross_total_cents']);
        $this->assertGreaterThan(0, $totals['net_payable_cents']);

        // Verify totals match individual calculations
        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertEquals($calc->gross_total_cents, $totals['gross_total_cents']);
        $this->assertEquals($calc->net_payable_cents, $totals['net_payable_cents']);
    }

    // ── T30: UNIQUE constraint payroll_line_id ──

    public function test_unique_constraint_payroll_line_id(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        // Try to create a second calculation for the same line
        $this->expectException(\Illuminate\Database\QueryException::class);
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $this->line->id,
            'rule_version' => 'test',
            'plafond_ss_monthly_cents' => 383400,
            'gross_total_cents' => 100000,
            'contributions_employee_cents' => 0,
            'contributions_employer_cents' => 0,
            'total_cost_employer_cents' => 0,
            'taxable_income_cents' => 0,
            'tax_cents' => 0,
            'net_before_tax_cents' => 0,
            'net_payable_cents' => 0,
            'contribution_lines' => [],
            'tax_breakdown' => [],
            'calculation_snapshot' => [],
            'status' => 'calculated',
            'calculated_at' => now(),
        ]);
    }

    // ── T31: Tax profile sans SSN fonctionne ──

    public function test_tax_profile_without_ssn_works(): void
    {
        // Create tax profile with just tax_rate_bps — no SSN needed
        EmployeeTaxProfile::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'tax_rate_bps' => 1100,
            'tax_rate_source' => 'manual',
            'tax_domicile' => 'FR',
            'effective_from' => '2026-01-01',
        ]);

        $resolver = new PayrollRuleResolver();
        $rate = $resolver->resolveTaxRate($this->company->id, $this->employee->id, now());

        $this->assertEquals(1100, $rate);

        // Full calculation should work too
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertNotNull($calc);
        $this->assertGreaterThan(0, $calc->tax_cents); // Has actual tax now
    }

    // ══════════════════════════════════════════════════════════
    // YTD Integration Tests (Phase 4.2-C — ADR-505)
    // ══════════════════════════════════════════════════════════

    // ── T32: ComputePayrollCalculations creates YTD per calculation ──

    public function test_compute_creates_ytd_snapshot(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $snapshot = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('payroll_calculation_id', $calc->id)
            ->first();

        $this->assertNotNull($snapshot, 'YTD snapshot should be created after compute');
        $this->assertEquals($this->company->id, $snapshot->company_id);
        $this->assertEquals($this->employee->id, $snapshot->employee_id);
        $this->assertEquals(2026, $snapshot->fiscal_year);
        $this->assertEquals(5, $snapshot->period_month);
        $this->assertEquals($calc->gross_total_cents, $snapshot->ytd_gross_total_cents);
        $this->assertEquals($calc->contributions_employee_cents, $snapshot->ytd_contributions_employee_cents);
        $this->assertContains(5, $snapshot->months_included);
        $this->assertEquals('ytd-snapshot-v1', $snapshot->snapshot_version);
    }

    // ── T33: Recompute replaces existing YTD snapshots ──

    public function test_recompute_replaces_ytd_snapshots(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);

        // First compute
        $useCase->execute($this->run, $this->user->id);
        $firstSnapshot = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->where('fiscal_year', 2026)
            ->where('period_month', 5)
            ->first();

        $this->assertNotNull($firstSnapshot);
        $firstId = $firstSnapshot->id;

        // Recompute — delete + recreate via cascade
        $useCase->execute($this->run, $this->user->id);

        // Only 1 YTD snapshot should exist for this employee/month
        $count = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->where('fiscal_year', 2026)
            ->where('period_month', 5)
            ->count();

        $this->assertEquals(1, $count, 'Recompute should result in exactly 1 YTD snapshot');
    }

    // ── T34: Validation locks calculation + YTD ──

    public function test_validation_locks_calculation_and_ytd(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        // Validate
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($this->run->fresh(), $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertEquals('validated', $calc->status);

        // Verify YTD snapshot still exists and linked to validated calculation
        $snapshot = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('payroll_calculation_id', $calc->id)
            ->first();

        $this->assertNotNull($snapshot, 'YTD snapshot should survive validation');

        // Calculation is now immutable — update should throw
        $this->expectException(\RuntimeException::class);
        $calc->update(['gross_total_cents' => 999999]);
    }

    // ── T35: Multi-month accumulates correctly via UseCase ──

    public function test_multi_month_ytd_accumulation_via_use_case(): void
    {
        // Month 1 (January)
        $run1 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 350000,
        ]);

        $ts1 = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
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

        PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run1->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $ts1->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 350000,
            'gross_breakdown' => ['base_salary_cents' => 350000, 'overtime_cents' => 0, 'unpaid_leave_deduction_cents' => 0, 'gross_basis_cents' => 350000],
            'compensation_snapshot' => ['base_salary_cents' => 350000, 'overtime_rate_bps' => 2500, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'benefits' => []],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($run1, $this->user->id);

        // Validate month 1 so it becomes validated
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($run1->fresh(), $this->user->id);

        // Month 2 (February) — use the existing $this->run but change period
        $this->run->update([
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
        ]);

        // Update the line's timesheet period dates
        $ts = TimesheetPeriod::withoutGlobalScopes()->find($this->line->timesheet_period_id);
        $ts->update(['period_start' => '2026-02-01', 'period_end' => '2026-02-28']);

        $useCase->execute($this->run, $this->user->id);

        // Check YTD for month 2 accumulates both months
        $snapshot2 = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->where('fiscal_year', 2026)
            ->where('period_month', 2)
            ->first();

        $this->assertNotNull($snapshot2);
        // Month 2 YTD should be roughly 2x month 1 (same salary)
        $snapshot1 = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->where('fiscal_year', 2026)
            ->where('period_month', 1)
            ->first();

        $this->assertNotNull($snapshot1);
        // YTD month 2 gross should equal month 1 gross + month 2 gross
        $calc2 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        $this->assertEquals(
            $snapshot1->ytd_gross_total_cents + $calc2->gross_total_cents,
            $snapshot2->ytd_gross_total_cents,
            'Month 2 YTD should cumulate month 1 + month 2'
        );
        $this->assertEquals([1, 2], $snapshot2->months_included);
    }

    // ── T36: Multi-tenant YTD isolation ──

    public function test_ytd_multi_tenant_isolation(): void
    {
        $company2 = Company::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'jobdomain_key' => 'logistique',
        ]);

        $employee2 = Employee::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'email' => 'marie@other.com',
            'employee_number' => 'EMP-002',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);

        // Compute for company 1
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        // Company 2's YTD should be empty
        $otherSnapshots = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $company2->id)
            ->count();

        $this->assertEquals(0, $otherSnapshots, 'Other company should have no YTD snapshots');

        // Company 1's YTD should exist
        $mySnapshots = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->count();

        $this->assertEquals(1, $mySnapshots);
    }

    // ── T37: payroll_calculation_id unique constraint respected ──

    public function test_ytd_payroll_calculation_id_unique(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        // Attempt to create a second YTD for the same calculation — should fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'fiscal_year' => 2026,
            'period_month' => 12, // different month, same calculation
            'payroll_calculation_id' => $calc->id,
            'ytd_gross_total_cents' => 0,
            'ytd_gross_basis_cents' => 0,
            'ytd_contributions_employee_cents' => 0,
            'ytd_contributions_employer_cents' => 0,
            'ytd_total_cost_employer_cents' => 0,
            'ytd_plafond_ss_cents' => 0,
            'ytd_base_plafonnee_cents' => 0,
            'ytd_base_tranche2_cents' => 0,
            'ytd_taxable_income_cents' => 0,
            'ytd_tax_cents' => 0,
            'ytd_net_before_tax_cents' => 0,
            'ytd_net_payable_cents' => 0,
            'ytd_benefits_cents' => 0,
            'ytd_deductions_cents' => 0,
            'snapshot_version' => 'ytd-snapshot-v1',
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // Progressive Regularization E2E Tests (Phase 4.3-C)
    // ══════════════════════════════════════════════════════════

    /**
     * Helper: create a computed PayrollRun + line for a given month.
     */
    private function createMonthRun(int $year, int $month, int $grossCents = 350000): array
    {
        $start = sprintf('%d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => $start,
            'period_end' => $end,
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => $grossCents,
        ]);

        $ts = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => $start,
            'period_end' => $end,
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

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $ts->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => $grossCents,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => $grossCents,
            'gross_breakdown' => ['base_salary_cents' => $grossCents, 'overtime_cents' => 0, 'unpaid_leave_deduction_cents' => 0, 'gross_basis_cents' => $grossCents],
            'compensation_snapshot' => ['base_salary_cents' => $grossCents, 'overtime_rate_bps' => 2500, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'benefits' => []],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        return [$run, $line];
    }

    // ── T38: Month 1 → no regularization snapshot ──

    public function test_month1_no_regularization_snapshot(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();

        // Month 1 (May 2026): no prior → no regularization
        $snapshot = $calc->calculation_snapshot;
        $this->assertArrayNotHasKey('regularization', $snapshot);
    }

    // ── T39: Month 2 → regularization applied with progressive method ──

    public function test_month2_regularization_applied(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);

        // Month 1 (January) — compute + validate
        [$run1, $line1] = $this->createMonthRun(2026, 1);
        $useCase->execute($run1, $this->user->id);
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($run1->fresh(), $this->user->id);

        $calcM1 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line1->id)
            ->first();

        // Month 2 (February) — compute (prior from month 1 validated)
        [$run2, $line2] = $this->createMonthRun(2026, 2);
        $useCase->execute($run2, $this->user->id);

        $calcM2 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line2->id)
            ->first();

        // Regularization snapshot must exist
        $snapshot = $calcM2->calculation_snapshot;
        $this->assertArrayHasKey('regularization', $snapshot);
        $this->assertEquals('progressive', $snapshot['regularization']['regularization_method']);
        $this->assertArrayHasKey('prior_cumuls', $snapshot['regularization']);
        $this->assertArrayHasKey('current_month', $snapshot['regularization']);
        $this->assertContains(1, $snapshot['regularization']['prior_cumuls']['months_included']);
    }

    // ── T40: Same salary → regularized amounts match monthly (backward compat) ──

    public function test_same_salary_regularized_matches_monthly(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);

        // Month 1 (January)
        [$run1, $line1] = $this->createMonthRun(2026, 1, 350000);
        $useCase->execute($run1, $this->user->id);
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($run1->fresh(), $this->user->id);

        $calcM1 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line1->id)
            ->first();

        // Month 2 (February) — same salary
        [$run2, $line2] = $this->createMonthRun(2026, 2, 350000);
        $useCase->execute($run2, $this->user->id);

        $calcM2 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line2->id)
            ->first();

        // Same salary, both under plafond → contributions should be very close
        // Allow ±1 cent tolerance due to floor rounding in progressive
        $this->assertEqualsWithDelta(
            $calcM1->contributions_employee_cents,
            $calcM2->contributions_employee_cents,
            1,
            'Same salary: regularized should match monthly ±1 cent'
        );
        $this->assertEqualsWithDelta(
            $calcM1->contributions_employer_cents,
            $calcM2->contributions_employer_cents,
            1,
            'Same salary: regularized employer should match monthly ±1 cent'
        );
    }

    // ── T41: Recompute is idempotent with regularization ──

    public function test_recompute_idempotent_with_regularization(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);

        // Month 1 — compute + validate
        [$run1, $line1] = $this->createMonthRun(2026, 1);
        $useCase->execute($run1, $this->user->id);
        $validateUseCase = new ValidatePayrollUseCase();
        $validateUseCase->execute($run1->fresh(), $this->user->id);

        // Month 2 — compute
        [$run2, $line2] = $this->createMonthRun(2026, 2);
        $useCase->execute($run2, $this->user->id);

        $calcFirst = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line2->id)
            ->first();
        $firstGross = $calcFirst->gross_total_cents;
        $firstContrib = $calcFirst->contributions_employee_cents;
        $firstTax = $calcFirst->tax_cents;

        // Recompute month 2
        $useCase->execute($run2, $this->user->id);

        $calcSecond = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line2->id)
            ->first();

        $this->assertEquals($firstGross, $calcSecond->gross_total_cents);
        $this->assertEquals($firstContrib, $calcSecond->contributions_employee_cents);
        $this->assertEquals($firstTax, $calcSecond->tax_cents);
    }

    // ── T42: Multi-month E2E — 3 months validated YTD consistency ──

    public function test_three_months_e2e_ytd_consistency(): void
    {
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $validateUseCase = new ValidatePayrollUseCase();

        // Month 1
        [$run1, $line1] = $this->createMonthRun(2026, 1, 350000);
        $useCase->execute($run1, $this->user->id);
        $validateUseCase->execute($run1->fresh(), $this->user->id);

        // Month 2
        [$run2, $line2] = $this->createMonthRun(2026, 2, 350000);
        $useCase->execute($run2, $this->user->id);
        $validateUseCase->execute($run2->fresh(), $this->user->id);

        // Month 3
        [$run3, $line3] = $this->createMonthRun(2026, 3, 350000);
        $useCase->execute($run3, $this->user->id);

        // Verify YTD snapshot for month 3
        $ytdM3 = \App\Core\Workforce\PayrollYtdSnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('employee_id', $this->employee->id)
            ->where('fiscal_year', 2026)
            ->where('period_month', 3)
            ->first();

        $this->assertNotNull($ytdM3);
        $this->assertEquals([1, 2, 3], $ytdM3->months_included);

        // YTD gross = 3 × 350000
        $this->assertEquals(350000 * 3, $ytdM3->ytd_gross_total_cents);

        // Regularization snapshot on M3
        $calcM3 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line3->id)
            ->first();

        $snapshot = $calcM3->calculation_snapshot;
        $this->assertArrayHasKey('regularization', $snapshot);
        $this->assertEquals([1, 2], $snapshot['regularization']['prior_cumuls']['months_included']);
    }
}
