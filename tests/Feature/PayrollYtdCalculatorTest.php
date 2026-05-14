<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\PayrollYtdSnapshot;
use App\Core\Workforce\Services\YtdCalculator;
use App\Core\Workforce\TimesheetPeriod;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollYtdCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR',
            'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000,
            'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '',
        ]);

        $this->seed(WorkforcePayrollRuleSeeder::class);

        $this->company = Company::create([
            'name' => 'YTD Test Co',
            'slug' => 'ytd-test-co',
            'jobdomain_key' => 'logistique',
        ]);

        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'email' => 'marie@ytd-test.com',
            'employee_number' => 'YTD-001',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);

        EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
        ]);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ── Test 1: Mois 1 = valeurs mensuelles ──

    public function test_month_1_equals_monthly_values(): void
    {
        $calc = $this->createValidatedCalculation(2026, 1, 350000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 1
        );

        $this->assertEquals($calc->gross_total_cents, $result['ytd_gross_total_cents']);
        $this->assertEquals($calc->contributions_employee_cents, $result['ytd_contributions_employee_cents']);
        $this->assertEquals($calc->contributions_employer_cents, $result['ytd_contributions_employer_cents']);
        $this->assertEquals($calc->total_cost_employer_cents, $result['ytd_total_cost_employer_cents']);
        $this->assertEquals($calc->taxable_income_cents, $result['ytd_taxable_income_cents']);
        $this->assertEquals($calc->tax_cents, $result['ytd_tax_cents']);
        $this->assertEquals($calc->net_before_tax_cents, $result['ytd_net_before_tax_cents']);
        $this->assertEquals($calc->net_payable_cents, $result['ytd_net_payable_cents']);
        $this->assertEquals($calc->plafond_ss_monthly_cents, $result['ytd_plafond_ss_cents']);
        $this->assertEquals([1], $result['months_included']);
    }

    // ── Test 2: Mois N = somme mois 1..N validés ──

    public function test_month_n_sums_all_validated_months(): void
    {
        $calc1 = $this->createValidatedCalculation(2026, 1, 350000);
        $calc2 = $this->createValidatedCalculation(2026, 2, 350000);
        $calc3 = $this->createValidatedCalculation(2026, 3, 400000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 3
        );

        $expectedGross = $calc1->gross_total_cents + $calc2->gross_total_cents + $calc3->gross_total_cents;
        $this->assertEquals($expectedGross, $result['ytd_gross_total_cents']);

        $expectedContribEmp = $calc1->contributions_employee_cents + $calc2->contributions_employee_cents + $calc3->contributions_employee_cents;
        $this->assertEquals($expectedContribEmp, $result['ytd_contributions_employee_cents']);

        $expectedTax = $calc1->tax_cents + $calc2->tax_cents + $calc3->tax_cents;
        $this->assertEquals($expectedTax, $result['ytd_tax_cents']);

        $expectedNet = $calc1->net_payable_cents + $calc2->net_payable_cents + $calc3->net_payable_cents;
        $this->assertEquals($expectedNet, $result['ytd_net_payable_cents']);

        $this->assertEquals([1, 2, 3], $result['months_included']);
    }

    // ── Test 3: Ignore PayrollCalculation non validated ──

    public function test_ignores_non_validated_calculations(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);
        $this->createCalculation(2026, 2, 350000, 'calculated'); // NOT validated

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 2
        );

        // Only month 1 should be included (month 2 calc is not validated)
        $this->assertEquals([1], $result['months_included']);
        $this->assertEquals(2, $result['period_month']); // period_month = requested month
        // Gross should be month 1 only
        $this->assertEquals(350000, $result['ytd_gross_total_cents']);
    }

    // ── Test 4: Recalculate after correction ──

    public function test_recalculate_year_after_correction(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);
        $this->createValidatedCalculation(2026, 2, 350000);
        $this->createValidatedCalculation(2026, 3, 350000);

        $snapshots = YtdCalculator::recalculateYear(
            $this->company->id, $this->employee->id, 2026
        );

        $this->assertCount(3, $snapshots);
        $this->assertArrayHasKey(1, $snapshots);
        $this->assertArrayHasKey(2, $snapshots);
        $this->assertArrayHasKey(3, $snapshots);

        // Month 1 = single month
        $this->assertEquals([1], $snapshots[1]['months_included']);
        // Month 2 = cumul 1+2
        $this->assertEquals([1, 2], $snapshots[2]['months_included']);
        // Month 3 = cumul 1+2+3
        $this->assertEquals([1, 2, 3], $snapshots[3]['months_included']);

        // Progressive accumulation
        $this->assertGreaterThan($snapshots[1]['ytd_gross_total_cents'], $snapshots[2]['ytd_gross_total_cents']);
        $this->assertGreaterThan($snapshots[2]['ytd_gross_total_cents'], $snapshots[3]['ytd_gross_total_cents']);
    }

    // ── Test 5: Fiscal year boundary ──

    public function test_fiscal_year_boundary_respected(): void
    {
        // December 2025
        $this->createValidatedCalculation(2025, 12, 350000);
        // January 2026
        $this->createValidatedCalculation(2026, 1, 400000);

        $result2026 = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 1
        );

        // Should only include January 2026, NOT December 2025
        $this->assertEquals([1], $result2026['months_included']);
        $this->assertEquals(400000, $result2026['ytd_gross_total_cents']);

        $result2025 = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2025, 12
        );

        // Should only include December 2025
        $this->assertEquals([12], $result2025['months_included']);
        $this->assertEquals(350000, $result2025['ytd_gross_total_cents']);
    }

    // ── Test 6: Multi-tenant isolation ──

    public function test_multi_tenant_isolation(): void
    {
        $companyB = Company::create([
            'name' => 'Other Co', 'slug' => 'other-co', 'jobdomain_key' => 'logistique',
        ]);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => User::factory()->create()->id,
            'first_name' => 'Pierre', 'last_name' => 'Durand',
            'email' => 'pierre@other.com', 'employee_number' => 'OTH-001',
            'hire_date' => '2024-01-01', 'status' => 'active',
        ]);

        // Create calculations for both companies
        $this->createValidatedCalculation(2026, 1, 500000); // company A
        $this->createValidatedCalculationFor($companyB, $employeeB, 2026, 1, 300000); // company B

        $resultA = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 1
        );

        $resultB = YtdCalculator::compute(
            $companyB->id, $employeeB->id, 2026, 1
        );

        // Company A sees only its own data
        $this->assertEquals(500000, $resultA['ytd_gross_total_cents']);
        // Company B sees only its own data
        $this->assertEquals(300000, $resultB['ytd_gross_total_cents']);
    }

    // ── Test 7: plafond_ss_ytd = somme plafonds mensuels ──

    public function test_plafond_ss_ytd_sums_monthly_plafonds(): void
    {
        $calc1 = $this->createValidatedCalculation(2026, 1, 350000);
        $calc2 = $this->createValidatedCalculation(2026, 2, 350000);
        $calc3 = $this->createValidatedCalculation(2026, 3, 350000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 3
        );

        $expectedPlafond = $calc1->plafond_ss_monthly_cents + $calc2->plafond_ss_monthly_cents + $calc3->plafond_ss_monthly_cents;
        $this->assertEquals($expectedPlafond, $result['ytd_plafond_ss_cents']);
        // 3 × monthly plafond
        $this->assertEquals($calc1->plafond_ss_monthly_cents * 3, $result['ytd_plafond_ss_cents']);
    }

    // ── Test 8: tranche_2_ytd = max(0, gross_ytd - plafond_ss_ytd) ──

    public function test_tranche_2_ytd_computed_correctly(): void
    {
        // Employee under plafond: gross 3500€, plafond ~3864€
        $this->createValidatedCalculation(2026, 1, 350000);
        $this->createValidatedCalculation(2026, 2, 350000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 2
        );

        $ytdGross = $result['ytd_gross_total_cents'];
        $ytdPlafond = $result['ytd_plafond_ss_cents'];

        // Base plafonnée = min(gross, plafond)
        $this->assertEquals(min($ytdGross, $ytdPlafond), $result['ytd_base_plafonnee_cents']);

        // Tranche 2 = max(0, min(gross, plafond×8) - plafond)
        $expectedT2 = max(0, min($ytdGross, $ytdPlafond * 8) - $ytdPlafond);
        $this->assertEquals($expectedT2, $result['ytd_base_tranche2_cents']);
    }

    // ── Test 9: rule_versions_used agrège les versions distinctes ──

    public function test_rule_versions_used_aggregates_distinct_versions(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);
        $this->createValidatedCalculation(2026, 2, 350000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 2
        );

        $this->assertArrayHasKey(1, $result['rule_versions_used']);
        $this->assertArrayHasKey(2, $result['rule_versions_used']);
        $this->assertNotEmpty($result['rule_versions_used'][1]);
        $this->assertNotEmpty($result['rule_versions_used'][2]);
    }

    // ── Test 10: months_included liste les mois inclus ──

    public function test_months_included_lists_actual_months(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);
        // Skip month 2
        $this->createValidatedCalculation(2026, 3, 350000);

        $result = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 3
        );

        // Month 2 was skipped — only 1 and 3 are present
        $this->assertEquals([1, 3], $result['months_included']);
    }

    // ── Test 11: Idempotence du compute ──

    public function test_compute_is_idempotent(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);
        $this->createValidatedCalculation(2026, 2, 400000);

        $result1 = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 2
        );

        $result2 = YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 2
        );

        // Exact same output
        $this->assertEquals($result1, $result2);
    }

    // ── Test 12: Pas d'effet de bord DB ──

    public function test_no_db_side_effects(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);

        $snapshotCountBefore = PayrollYtdSnapshot::withoutGlobalScopes()->count();

        YtdCalculator::compute(
            $this->company->id, $this->employee->id, 2026, 1
        );

        YtdCalculator::recalculateYear(
            $this->company->id, $this->employee->id, 2026
        );

        YtdCalculator::getPriorCumuls(
            $this->company->id, $this->employee->id, 2026, 2
        );

        $snapshotCountAfter = PayrollYtdSnapshot::withoutGlobalScopes()->count();

        // ZERO writes to DB
        $this->assertEquals($snapshotCountBefore, $snapshotCountAfter);
    }

    // ── Test 13 (bonus): getPriorCumuls returns null for month 1 ──

    public function test_get_prior_cumuls_null_for_month_1(): void
    {
        $this->createValidatedCalculation(2026, 1, 350000);

        $result = YtdCalculator::getPriorCumuls(
            $this->company->id, $this->employee->id, 2026, 1
        );

        $this->assertNull($result);
    }

    // ── Test 14 (bonus): getPriorCumuls returns month 1 data for month 2 ──

    public function test_get_prior_cumuls_returns_prior_data(): void
    {
        $calc1 = $this->createValidatedCalculation(2026, 1, 350000);
        $this->createValidatedCalculation(2026, 2, 400000);

        $result = YtdCalculator::getPriorCumuls(
            $this->company->id, $this->employee->id, 2026, 2
        );

        $this->assertNotNull($result);
        $this->assertEquals($calc1->gross_total_cents, $result['ytd_gross_total_cents']);
        $this->assertEquals([1], $result['months_included']);
    }

    // ── Helpers ──

    private function createValidatedCalculation(int $year, int $month, int $grossCents): PayrollCalculation
    {
        return $this->createCalculation($year, $month, $grossCents, PayrollCalculation::STATUS_VALIDATED);
    }

    private function createCalculation(int $year, int $month, int $grossCents, string $status): PayrollCalculation
    {
        return $this->createCalculationFor($this->company, $this->employee, $year, $month, $grossCents, $status);
    }

    private function createValidatedCalculationFor(Company $company, Employee $employee, int $year, int $month, int $grossCents): PayrollCalculation
    {
        return $this->createCalculationFor($company, $employee, $year, $month, $grossCents, PayrollCalculation::STATUS_VALIDATED);
    }

    private function createCalculationFor(
        Company $company,
        Employee $employee,
        int $year,
        int $month,
        int $grossCents,
        string $status,
    ): PayrollCalculation {
        $periodStart = sprintf('%d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => $grossCents,
        ]);

        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
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
            'company_id' => $company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
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
            'base_salary_cents' => $grossCents,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => $grossCents,
            'gross_breakdown' => [
                'base_salary_cents' => $grossCents,
                'overtime_cents' => 0,
                'unpaid_leave_deduction_cents' => 0,
                'gross_basis_cents' => $grossCents,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => $grossCents,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'benefits' => [],
            ],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        // Simulate realistic contribution & tax values
        $plafondSS = 386400; // FR monthly plafond
        $contribEmployee = (int) round($grossCents * 2200 / 10000); // ~22%
        $contribEmployer = (int) round($grossCents * 4500 / 10000); // ~45%
        $taxableIncome = $grossCents - $contribEmployee;
        $taxCents = (int) round($taxableIncome * 700 / 10000); // ~7% PAS
        $netBeforeTax = $grossCents - $contribEmployee;
        $netPayable = $netBeforeTax - $taxCents;
        $totalCostEmployer = $grossCents + $contribEmployer;

        $contributionLines = [
            'lines' => [
                ['code' => 'maladie', 'label' => 'Maladie', 'category' => 'securite_sociale', 'employee_cents' => (int) round($grossCents * 70 / 10000), 'employer_cents' => (int) round($grossCents * 700 / 10000)],
                ['code' => 'vieillesse_plafonnee', 'label' => 'Vieillesse plafonnée', 'category' => 'securite_sociale', 'employee_cents' => (int) round(min($grossCents, $plafondSS) * 690 / 10000), 'employer_cents' => (int) round(min($grossCents, $plafondSS) * 855 / 10000)],
                ['code' => 'chomage', 'label' => 'Chômage', 'category' => 'chomage', 'employee_cents' => 0, 'employer_cents' => (int) round($grossCents * 405 / 10000)],
            ],
            'total_employee_cents' => $contribEmployee,
            'total_employer_cents' => $contribEmployer,
        ];

        return PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'payroll_line_id' => $line->id,
            'rule_version' => 'payroll-calc-v2',
            'plafond_ss_monthly_cents' => $plafondSS,
            'gross_total_cents' => $grossCents,
            'contributions_employee_cents' => $contribEmployee,
            'contributions_employer_cents' => $contribEmployer,
            'total_cost_employer_cents' => $totalCostEmployer,
            'taxable_income_cents' => $taxableIncome,
            'tax_cents' => $taxCents,
            'net_before_tax_cents' => $netBeforeTax,
            'net_payable_cents' => $netPayable,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => $contributionLines,
            'tax_breakdown' => ['taxable_income_cents' => $taxableIncome, 'tax_rate_bps' => 700, 'tax_cents' => $taxCents],
            'benefit_lines' => null,
            'deduction_lines' => null,
            'blocking_anomalies' => null,
            'calculation_snapshot' => [
                'snapshot_version' => 'calc-snapshot-v2',
                'rule_version' => 'payroll-calc-v2',
                'resolver_version' => 'payroll-resolver-v2',
            ],
            'status' => $status,
            'calculated_at' => now(),
            'calculated_by' => $this->user->id,
        ]);
    }
}
