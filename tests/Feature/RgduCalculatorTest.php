<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\DTOs\ContributionLine;
use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\DTOs\RgduResult;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Services\RgduCalculator;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 6.1: RGDU (Réduction Générale Dégressive Unifiée) calculator tests.
 *
 * Tests:
 * - Unit: RgduCalculator pure function with synthetic contributions
 * - Integration: Full PayrollCalculationEngine pipeline with seeded rules
 *
 * ⚠️ INDICATIF — taux de cotisations simplifiés.
 * Validation expert-comptable requise avant production.
 */
class RgduCalculatorTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════
    // Helpers: synthetic ContributionResult
    // ═══════════════════════════════════════════════════════

    /**
     * Build a ContributionResult with configurable employer cents per eligible code.
     * Simulates a typical full-time SMIC-level employee.
     */
    private function buildContributions(int $grossCents, int $plafondSS = 383400): ContributionResult
    {
        $lines = [
            new ContributionLine('urssaf_maladie', 'Maladie', 'urssaf', 'deplafonne', $grossCents, 0, 700, 0, (int) round($grossCents * 700 / 10000)),
            new ContributionLine('at_mp', 'AT/MP', 'urssaf', 'deplafonne', $grossCents, 0, 120, 0, (int) round($grossCents * 120 / 10000)),
            new ContributionLine('urssaf_vieillesse_plaf', 'Vieillesse plaf', 'urssaf', 'plafonne_ss', min($grossCents, $plafondSS), 690, 855, (int) round(min($grossCents, $plafondSS) * 690 / 10000), (int) round(min($grossCents, $plafondSS) * 855 / 10000)),
            new ContributionLine('urssaf_vieillesse_deplaf', 'Vieillesse déplaf', 'urssaf', 'deplafonne', $grossCents, 40, 190, (int) round($grossCents * 40 / 10000), (int) round($grossCents * 190 / 10000)),
            new ContributionLine('allocations_familiales', 'AF', 'urssaf', 'deplafonne', $grossCents, 0, 525, 0, (int) round($grossCents * 525 / 10000)),
            new ContributionLine('fnal', 'FNAL', 'urssaf', 'deplafonne', $grossCents, 0, 50, 0, (int) round($grossCents * 50 / 10000)),
            new ContributionLine('csa', 'CSA', 'urssaf', 'deplafonne', $grossCents, 0, 30, 0, (int) round($grossCents * 30 / 10000)),
            new ContributionLine('chomage', 'Chômage', 'chomage', 'plafonne_ss', min($grossCents, $plafondSS), 0, 405, 0, (int) round(min($grossCents, $plafondSS) * 405 / 10000)),
            new ContributionLine('retraite_t1', 'Retraite T1', 'retraite', 'tranche_1', min($grossCents, $plafondSS), 386, 604, (int) round(min($grossCents, $plafondSS) * 386 / 10000), (int) round(min($grossCents, $plafondSS) * 604 / 10000)),
            new ContributionLine('ceg_t1', 'CEG T1', 'retraite', 'tranche_1', min($grossCents, $plafondSS), 86, 129, (int) round(min($grossCents, $plafondSS) * 86 / 10000), (int) round(min($grossCents, $plafondSS) * 129 / 10000)),
            new ContributionLine('cet', 'CET', 'retraite', 'tranche_1', min($grossCents, $plafondSS), 14, 21, (int) round(min($grossCents, $plafondSS) * 14 / 10000), (int) round(min($grossCents, $plafondSS) * 21 / 10000)),
            // Non-eligible contributions
            new ContributionLine('csg_deductible', 'CSG déd', 'csg_crds', 'csg_base', (int) round($grossCents * 0.9825), 681, 0, (int) round($grossCents * 0.9825 * 681 / 10000), 0),
            new ContributionLine('csg_non_deductible', 'CSG ND', 'csg_crds', 'csg_base', (int) round($grossCents * 0.9825), 242, 0, (int) round($grossCents * 0.9825 * 242 / 10000), 0),
            new ContributionLine('crds', 'CRDS', 'csg_crds', 'csg_base', (int) round($grossCents * 0.9825), 50, 0, (int) round($grossCents * 0.9825 * 50 / 10000), 0),
        ];

        $totalEmployee = 0;
        $totalEmployer = 0;
        $csgND = 0;
        foreach ($lines as $l) {
            $totalEmployee += $l->employeeCents;
            $totalEmployer += $l->employerCents;
            if ($l->code === 'csg_non_deductible') {
                $csgND = $l->employeeCents;
            }
        }

        return new ContributionResult(
            lines: $lines,
            totalEmployeeCents: $totalEmployee,
            totalEmployerCents: $totalEmployer,
            csgNonDeductibleCents: $csgND,
        );
    }

    // ═══════════════════════════════════════════════════════
    // UNIT TESTS — RgduCalculator::compute() pure function
    // ═══════════════════════════════════════════════════════

    public function test_smic_salary_gets_maximum_reduction(): void
    {
        // SMIC mensuel brut = 1147 cents/h × 151.67h = 173 965 cents (≈ 1739.65 EUR)
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        $grossCents = (int) round($smicHoraire * $monthlyHours); // 173965
        $coefficientT = 3195; // <50 employees

        $contributions = $this->buildContributions($grossCents);

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        // At SMIC: ratio = 1.0, C = (T/0.6) × (1.6 × 1 − 1) = (T/0.6) × 0.6 = T
        $this->assertTrue($result->eligible);
        $this->assertEquals($coefficientT, $result->coefficientBps, 'At SMIC salary, coefficient should equal T');
        $this->assertGreaterThan(0, $result->reliefCents);
        $this->assertEquals('rgdu-v1', $result->formulaVersion);
    }

    public function test_salary_above_1_6_smic_gets_zero_reduction(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        $smicMensuel = (int) round($smicHoraire * $monthlyHours);
        // Salary = 2 × SMIC (well above 1.6 threshold)
        $grossCents = $smicMensuel * 2;
        $coefficientT = 3195;

        $contributions = $this->buildContributions($grossCents);

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        $this->assertFalse($result->eligible);
        $this->assertEquals(0, $result->coefficientBps);
        $this->assertEquals(0, $result->reliefCents);
    }

    public function test_salary_at_exactly_1_6_smic_gets_zero_reduction(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        $smicMensuel = (int) round($smicHoraire * $monthlyHours);
        // Salary = exactly 1.6 × SMIC
        $grossCents = (int) round($smicMensuel * 1.6);
        $coefficientT = 3195;

        $contributions = $this->buildContributions($grossCents);

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        // At 1.6×SMIC: C = (T/0.6) × (1.6 × 1/1.6 − 1) = (T/0.6) × 0 = 0
        $this->assertEquals(0, $result->coefficientBps);
        $this->assertEquals(0, $result->reliefCents);
    }

    public function test_part_time_prorates_smic(): void
    {
        $smicHoraire = 1147;
        // Part-time: 28h/week → 28 × 52/12 = 121.33 h/month
        $monthlyHours = 28 * 52 / 12;
        $smicProratise = (int) round($smicHoraire * $monthlyHours);
        // Salary at SMIC proratisé
        $grossCents = $smicProratise;
        $coefficientT = 3195;

        $contributions = $this->buildContributions($grossCents);

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        // At SMIC level: coefficient should be max (T)
        $this->assertTrue($result->eligible);
        $this->assertEquals($coefficientT, $result->coefficientBps);
        $this->assertEquals($smicProratise, $result->smicProratiseCents);
        // Relief should be lower than full-time (lower base)
        $this->assertGreaterThan(0, $result->reliefCents);
    }

    public function test_overtime_increases_smic_proratise(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67; // full-time
        $overtimeHours = 5.0;

        // Total SMIC proratisé includes overtime
        $expectedSmicProratise = (int) round($smicHoraire * ($monthlyHours + $overtimeHours));

        // Salary slightly above SMIC base but below proratisé threshold
        $grossCents = (int) round($smicHoraire * $monthlyHours) + 10000; // SMIC + 100 EUR

        $contributions = $this->buildContributions($grossCents);

        $resultWithOT = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: $overtimeHours,
            coefficientTBps: 3195,
            contributions: $contributions,
        );

        $resultWithoutOT = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: 3195,
            contributions: $contributions,
        );

        $this->assertEquals($expectedSmicProratise, $resultWithOT->smicProratiseCents);
        // With overtime, SMIC proratisé is higher → ratio higher → coefficient higher → MORE relief
        $this->assertGreaterThanOrEqual($resultWithoutOT->coefficientBps, $resultWithOT->coefficientBps);
        $this->assertGreaterThanOrEqual($resultWithoutOT->reliefCents, $resultWithOT->reliefCents);
    }

    public function test_coefficient_capped_at_t(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        // Salary BELOW SMIC (shouldn't happen but tests capping)
        $grossCents = (int) round($smicHoraire * $monthlyHours * 0.8); // 80% of SMIC
        $coefficientT = 3195;

        $contributions = $this->buildContributions($grossCents);

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        // Raw coefficient would exceed T, must be capped
        $this->assertEquals($coefficientT, $result->coefficientBps, 'Coefficient must be capped at T');
    }

    public function test_relief_capped_at_eligible_contributions(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        $grossCents = (int) round($smicHoraire * $monthlyHours);
        $coefficientT = 3195;

        // Build contributions with VERY LOW employer amounts (edge case)
        $lines = [
            new ContributionLine('urssaf_maladie', 'Maladie', 'urssaf', 'deplafonne', $grossCents, 0, 10, 0, 100),
        ];
        $contributions = new ContributionResult(
            lines: $lines,
            totalEmployeeCents: 0,
            totalEmployerCents: 100,
            csgNonDeductibleCents: 0,
        );

        $result = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
        );

        // Relief should be capped at the eligible employer contributions (100 cents)
        $this->assertLessThanOrEqual(100, $result->reliefCents);
        $this->assertEquals(100, $result->eligibleEmployerContributionsCents);
    }

    public function test_fifty_or_more_employees_uses_higher_coefficient(): void
    {
        $smicHoraire = 1147;
        $monthlyHours = 151.67;
        // Salary at 1.2 × SMIC (gets partial reduction)
        $grossCents = (int) round($smicHoraire * $monthlyHours * 1.2);

        $contributions = $this->buildContributions($grossCents);

        $resultSmall = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: 3195, // <50 employees
            contributions: $contributions,
        );

        $resultLarge = RgduCalculator::compute(
            grossTotalCents: $grossCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $monthlyHours,
            overtimeHours: 0,
            coefficientTBps: 3235, // ≥50 employees
            contributions: $contributions,
        );

        // Higher T → higher coefficient → MORE relief
        $this->assertGreaterThan($resultSmall->coefficientBps, $resultLarge->coefficientBps);
        $this->assertGreaterThan($resultSmall->reliefCents, $resultLarge->reliefCents);
    }

    public function test_zero_gross_returns_zero(): void
    {
        $contributions = $this->buildContributions(0);

        $result = RgduCalculator::compute(
            grossTotalCents: 0,
            smicHourlyCents: 1147,
            contractedMonthlyHours: 151.67,
            overtimeHours: 0,
            coefficientTBps: 3195,
            contributions: $contributions,
        );

        $this->assertFalse($result->eligible);
        $this->assertEquals(0, $result->reliefCents);
    }

    public function test_rgdu_result_to_array(): void
    {
        $result = new RgduResult(
            coefficientBps: 3195,
            smicProratiseCents: 173965,
            reliefCents: 55582,
            eligibleEmployerContributionsCents: 60000,
            grossTotalCents: 173965,
            contractedMonthlyHours: 151.67,
            overtimeHours: 0,
            eligible: true,
            formulaVersion: 'rgdu-v1',
        );

        $arr = $result->toArray();
        $this->assertEquals(3195, $arr['coefficient_bps']);
        $this->assertEquals(173965, $arr['smic_proratise_cents']);
        $this->assertEquals(55582, $arr['relief_cents']);
        $this->assertTrue($arr['eligible']);
        $this->assertEquals('rgdu-v1', $arr['formula_version']);
    }

    // ═══════════════════════════════════════════════════════
    // INTEGRATION TESTS — Full pipeline with seeded rules
    // ═══════════════════════════════════════════════════════

    private Company $company;
    private User $user;
    private Employee $employee;
    private EmploymentContract $contract;

    private function setupIntegrationFixtures(int $baseSalaryCents = 173965, float $weeklyHours = 35, ?int $headcount = null): void
    {
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

        PlatformModule::create([
            'key' => 'workforce_payroll',
            'name' => 'Payroll',
            'is_enabled_globally' => true,
        ]);

        $this->company = Company::create([
            'name' => 'RGDU Co',
            'slug' => 'rgdu-co',
            'jobdomain_key' => 'logistique',
            'average_headcount' => $headcount,
        ]);
        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Alice',
            'last_name' => 'Dupuis',
            'email' => 'alice@rgdu.test',
            'employee_number' => 'EMP-RGDU-001',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $this->contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => $weeklyHours,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
            'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => $baseSalaryCents,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        app()->instance('company.context', $this->company);
    }

    private function createTimesheetAndRun(int $totalOvertimeMinutes = 0, array $dailyOvertimeEntries = []): PayrollRun
    {
        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100 + $totalOvertimeMinutes,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        foreach ($dailyOvertimeEntries as $date => $dailyOtMinutes) {
            TimesheetLine::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'timesheet_period_id' => $timesheet->id,
                'employee_id' => $this->employee->id,
                'date' => $date,
                'worked_minutes' => 480 + $dailyOtMinutes,
                'break_minutes' => 0,
                'daily_overtime_minutes' => $dailyOtMinutes,
                'planned_minutes' => 480,
                'leave_minutes' => 0,
                'is_leave_day' => false,
                'is_rest_day' => false,
                'source_snapshot' => [],
            ]);
        }

        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'employee_count' => 0,
            'total_worked_minutes' => 0,
            'total_gross_cents' => 0,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'rgdu-test:' . uniqid(),
        ]);

        return app(ComputePayrollUseCase::class)->execute($run, $this->user->id);
    }

    public function test_integration_smic_salary_gets_rgdu_relief(): void
    {
        // SMIC mensuel = 1147 × 151.67 = 173965 cents
        $this->setupIntegrationFixtures(baseSalaryCents: 173965);
        $run = $this->createTimesheetAndRun();

        // Compute calculations
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        $this->assertNotNull($calc);

        // relief_lines should contain RGDU
        $reliefLines = $calc->relief_lines;
        $this->assertNotNull($reliefLines);
        $this->assertGreaterThan(0, $reliefLines['total_employer_relief_cents']);
        $this->assertCount(1, $reliefLines['lines']);
        $this->assertEquals('rgdu', $reliefLines['lines'][0]['code']);
        $this->assertArrayHasKey('rgdu_detail', $reliefLines['lines'][0]);

        $rgduDetail = $reliefLines['lines'][0]['rgdu_detail'];
        $this->assertTrue($rgduDetail['eligible']);
        $this->assertEquals('rgdu-v1', $rgduDetail['formula_version']);
    }

    public function test_integration_high_salary_gets_no_relief(): void
    {
        // Salary = 5000 EUR = well above 1.6 × SMIC (≈2783 EUR)
        $this->setupIntegrationFixtures(baseSalaryCents: 500000);
        $run = $this->createTimesheetAndRun();

        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        $reliefLines = $calc->relief_lines;
        $this->assertEquals(0, $reliefLines['total_employer_relief_cents']);
        $this->assertEmpty($reliefLines['lines']);
    }

    public function test_integration_net_payable_unchanged_by_rgdu(): void
    {
        // RGDU = employer relief only, must NOT change net payable
        $this->setupIntegrationFixtures(baseSalaryCents: 200000); // Slightly above SMIC → partial relief

        $run = $this->createTimesheetAndRun();
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        // Net payable = gross - employee contributions - deductions - tax
        // RGDU does NOT affect employee contributions or tax
        $expectedNetBeforeTax = $calc->gross_total_cents - $calc->contributions_employee_cents - $calc->deductions_cents;
        $expectedNetPayable = $expectedNetBeforeTax - $calc->tax_cents;

        $this->assertEquals($expectedNetBeforeTax, $calc->net_before_tax_cents);
        $this->assertEquals($expectedNetPayable, $calc->net_payable_cents);
    }

    public function test_integration_relief_in_snapshot(): void
    {
        $this->setupIntegrationFixtures(baseSalaryCents: 173965);
        $run = $this->createTimesheetAndRun();
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        $snapshot = $calc->calculation_snapshot;
        $this->assertArrayHasKey('relief_summary', $snapshot);
        $this->assertGreaterThan(0, $snapshot['relief_summary']['total_employer_relief_cents']);
        $this->assertCount(1, $snapshot['relief_summary']['lines']);
    }

    public function test_integration_missing_rgdu_rule_creates_blocking_anomaly(): void
    {
        $this->setupIntegrationFixtures(baseSalaryCents: 173965);

        // Delete RGDU meta-rules to simulate missing rules
        MarketRuleSet::where('rule_key', 'smic_horaire_cents')->delete();
        MarketRuleSet::where('rule_key', 'rgdu_coefficient_t')->delete();

        $run = $this->createTimesheetAndRun();
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        // Should have blocking anomaly for missing RGDU rules
        $anomalies = $calc->blocking_anomalies;
        $this->assertNotEmpty($anomalies);

        $rgduAnomaly = collect($anomalies)->firstWhere('type', 'missing_rgdu_rule');
        $this->assertNotNull($rgduAnomaly, 'Expected missing_rgdu_rule blocking anomaly');

        // Relief should be stub (zero)
        $reliefLines = $calc->relief_lines;
        $this->assertEquals(0, $reliefLines['total_employer_relief_cents']);
    }

    public function test_integration_part_time_28h(): void
    {
        // 28h/week part-time at prorated SMIC
        $monthlyHours = 28 * 52 / 12; // 121.33h
        $smicProratise = (int) round(1147 * $monthlyHours); // 139167 cents
        $this->setupIntegrationFixtures(baseSalaryCents: $smicProratise, weeklyHours: 28);

        $run = $this->createTimesheetAndRun();
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        $reliefLines = $calc->relief_lines;
        $this->assertGreaterThan(0, $reliefLines['total_employer_relief_cents']);
    }

    public function test_integration_with_overtime_hours(): void
    {
        // Salary at SMIC, 5h overtime → higher SMIC proratisé → potentially higher relief
        $this->setupIntegrationFixtures(baseSalaryCents: 173965);
        $run = $this->createTimesheetAndRun(totalOvertimeMinutes: 300); // 5h overtime

        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $calc = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->first();

        $reliefLines = $calc->relief_lines;
        $this->assertGreaterThan(0, $reliefLines['total_employer_relief_cents']);

        // Verify RGDU detail includes overtime
        $rgduDetail = $reliefLines['lines'][0]['rgdu_detail'];
        $this->assertGreaterThan(0, $rgduDetail['overtime_hours']);
    }

    public function test_integration_headcount_50_uses_higher_t(): void
    {
        // Two companies: <50 and ≥50 employees, same salary
        $this->setupIntegrationFixtures(baseSalaryCents: 200000, headcount: 10);
        $runSmall = $this->createTimesheetAndRun();
        app(ComputePayrollCalculationsUseCase::class)->execute($runSmall, $this->user->id);

        $calcSmall = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $runSmall->lines()->pluck('id'))
            ->first();
        $reliefSmall = $calcSmall->relief_lines['total_employer_relief_cents'];

        // Cleanup for second run
        app()->forgetInstance('company.context');
        Model::reguard();
        Model::unguard();

        // Second company with ≥50 employees
        $company2 = Company::create([
            'name' => 'RGDU Big Co',
            'slug' => 'rgdu-big-co',
            'jobdomain_key' => 'logistique',
            'average_headcount' => 55,
        ]);
        $employee2 = Employee::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'user_id' => $this->user->id,
            'first_name' => 'Bob',
            'last_name' => 'Grand',
            'email' => 'bob@rgdu.test',
            'employee_number' => 'EMP-RGDU-002',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $contract2 = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'employee_id' => $employee2->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
            'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'contract_id' => $contract2->id,
            'base_salary_cents' => 200000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);
        TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'employee_id' => $employee2->id,
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

        app()->instance('company.context', $company2);
        $run2 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $company2->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'EUR',
            'employee_count' => 0,
            'total_worked_minutes' => 0,
            'total_gross_cents' => 0,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'rgdu-big:' . uniqid(),
        ]);
        $runLarge = app(ComputePayrollUseCase::class)->execute($run2, $this->user->id);
        app(ComputePayrollCalculationsUseCase::class)->execute($runLarge, $this->user->id);

        $calcLarge = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $runLarge->lines()->pluck('id'))
            ->first();
        $reliefLarge = $calcLarge->relief_lines['total_employer_relief_cents'];

        // ≥50 employees has higher T (3235 vs 3195) → higher relief
        $this->assertGreaterThan($reliefSmall, $reliefLarge);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }
}
