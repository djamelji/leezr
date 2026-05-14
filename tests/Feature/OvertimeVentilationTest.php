<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 6.2: Overtime ventilation for DSN S21.G00.53 + RGDU.
 *
 * Tests that gross_breakdown correctly splits overtime into:
 * - overtime_25_cents / overtime_25_hours (HS 25% — first 8h above legal weekly hours)
 * - overtime_50_cents / overtime_50_hours (HS 50% — beyond 43h/week)
 * - overtime_daily_cents / overtime_daily_hours (daily overtime from timesheet lines)
 * - base_hours / total_hours
 *
 * Constraint: ZERO modification of financial calculators.
 * The total overtime_cents must remain identical to the ventilated sum.
 */
class OvertimeVentilationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private EmploymentContract $contract;

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

        PlatformModule::create([
            'key' => 'workforce_payroll',
            'name' => 'Payroll',
            'is_enabled_globally' => true,
        ]);

        $this->company = Company::create([
            'name' => 'Overtime Co',
            'slug' => 'overtime-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'jean@overtime.test',
            'employee_number' => 'EMP-OT-001',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $this->contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
            'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 300000, // 3000 EUR
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500, // 25% multiplier
            'effective_from' => '2025-01-01',
        ]);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Create a timesheet with specified overtime minutes and timesheet lines.
     *
     * @param int $totalOvertimeMinutes Total overtime minutes on the timesheet period
     * @param array $dailyOvertimeEntries Array of [date => daily_overtime_minutes] for timesheet lines
     */
    private function createTimesheetWithOvertime(
        int $totalOvertimeMinutes,
        array $dailyOvertimeEntries = [],
    ): TimesheetPeriod {
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

        // Create timesheet lines with daily overtime
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

        return $timesheet;
    }

    private function createDraftRun(): PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()->create([
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
            'idempotency_key' => 'ot-test:' . uniqid(),
        ]);
    }

    private function computeAndGetLine(PayrollRun $run): PayrollLine
    {
        $result = app(ComputePayrollUseCase::class)->execute($run, $this->user->id);

        return PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $result->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════
    // Zero overtime (backward compatibility)
    // ═══════════════════════════════════════════════════════

    public function test_zero_overtime_has_ventilation_fields(): void
    {
        $this->createTimesheetWithOvertime(0);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertSame(0, $gb['overtime_cents']);
        $this->assertSame(0, $gb['overtime_25_cents']);
        $this->assertSame(0, $gb['overtime_50_cents']);
        $this->assertSame(0, $gb['overtime_daily_cents']);
        $this->assertEquals(0.0, $gb['overtime_25_hours']);
        $this->assertEquals(0.0, $gb['overtime_50_hours']);
        $this->assertEquals(0.0, $gb['overtime_daily_hours']);
        $this->assertArrayHasKey('base_hours', $gb);
        $this->assertArrayHasKey('total_hours', $gb);
        $this->assertEquals($gb['base_hours'], $gb['total_hours']);
    }

    // ═══════════════════════════════════════════════════════
    // Weekly overtime only — HS 25% (≤8h)
    // ═══════════════════════════════════════════════════════

    public function test_weekly_overtime_under_8h_all_hs25(): void
    {
        // 5 hours of weekly overtime = 300 minutes, no daily overtime
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(5.0, $gb['overtime_25_hours']);
        $this->assertEquals(0.0, $gb['overtime_50_hours']);
        $this->assertEquals(0.0, $gb['overtime_daily_hours']);
        $this->assertGreaterThan(0, $gb['overtime_25_cents']);
        $this->assertSame(0, $gb['overtime_50_cents']);
        $this->assertSame(0, $gb['overtime_daily_cents']);

        // Ventilated sum == total
        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum, 'Ventilated sum must equal total overtime_cents');
    }

    public function test_weekly_overtime_exactly_8h_all_hs25(): void
    {
        // 8 hours = 480 minutes (boundary)
        $this->createTimesheetWithOvertime(480);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(8.0, $gb['overtime_25_hours']);
        $this->assertEquals(0.0, $gb['overtime_50_hours']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
    }

    // ═══════════════════════════════════════════════════════
    // Weekly overtime — HS 25% + HS 50% (>8h)
    // ═══════════════════════════════════════════════════════

    public function test_weekly_overtime_over_8h_splits_hs25_hs50(): void
    {
        // 12 hours = 720 minutes → 8h HS25 + 4h HS50
        $this->createTimesheetWithOvertime(720);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(8.0, $gb['overtime_25_hours']);
        $this->assertEquals(4.0, $gb['overtime_50_hours']);
        $this->assertEquals(0.0, $gb['overtime_daily_hours']);
        $this->assertGreaterThan(0, $gb['overtime_50_cents']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
    }

    // ═══════════════════════════════════════════════════════
    // Mixed: daily + weekly overtime
    // ═══════════════════════════════════════════════════════

    public function test_mixed_daily_and_weekly_overtime(): void
    {
        // Total = 420 min (7h), daily = 120 min (2h), weekly = 300 min (5h)
        $this->createTimesheetWithOvertime(420, [
            '2026-05-05' => 60,  // 1h daily overtime
            '2026-05-12' => 60,  // 1h daily overtime
        ]);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(2.0, $gb['overtime_daily_hours']);
        $this->assertEquals(5.0, $gb['overtime_25_hours']); // 5h weekly < 8h threshold
        $this->assertEquals(0.0, $gb['overtime_50_hours']);
        $this->assertGreaterThan(0, $gb['overtime_daily_cents']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
    }

    public function test_mixed_daily_weekly_with_hs50(): void
    {
        // Total = 780 min (13h), daily = 180 min (3h), weekly = 600 min (10h)
        // Weekly: 8h HS25 + 2h HS50
        $this->createTimesheetWithOvertime(780, [
            '2026-05-05' => 60,
            '2026-05-12' => 60,
            '2026-05-19' => 60,
        ]);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(3.0, $gb['overtime_daily_hours']);
        $this->assertEquals(8.0, $gb['overtime_25_hours']);
        $this->assertEquals(2.0, $gb['overtime_50_hours']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
    }

    // ═══════════════════════════════════════════════════════
    // Financial invariants
    // ═══════════════════════════════════════════════════════

    public function test_gross_basis_unchanged_by_ventilation(): void
    {
        // 5h overtime
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        // gross_basis = base_salary + overtime - unpaid_leave
        $expectedGross = $gb['base_salary_cents'] + $gb['overtime_cents'] - $gb['unpaid_leave_deduction_cents'];
        $this->assertSame($expectedGross, $gb['gross_basis_cents'], 'Ventilation must NOT change gross_basis_cents');
        $this->assertSame($expectedGross, $line->gross_basis_cents);
    }

    public function test_total_hours_equals_base_plus_overtime(): void
    {
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $expectedTotal = $gb['base_hours'] + $gb['overtime_hours'];
        $this->assertEquals(round($expectedTotal, 2), $gb['total_hours']);
    }

    public function test_overtime_hours_sum_matches_total(): void
    {
        // Mixed overtime: total 10h, daily 2h, weekly 8h
        $this->createTimesheetWithOvertime(600, [
            '2026-05-05' => 60,
            '2026-05-12' => 60,
        ]);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $ventilatedHours = $gb['overtime_25_hours'] + $gb['overtime_50_hours'] + $gb['overtime_daily_hours'];
        $this->assertEquals($gb['overtime_hours'], round($ventilatedHours, 2));
    }

    // ═══════════════════════════════════════════════════════
    // Calculation snapshot enrichment
    // ═══════════════════════════════════════════════════════

    public function test_calculation_snapshot_includes_overtime_ventilation(): void
    {
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        app(ComputePayrollUseCase::class)->execute($run, $this->user->id);
        $run->refresh();

        // Now compute calculations
        app(ComputePayrollCalculationsUseCase::class)->execute($run, $this->user->id);

        $line = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $calc = $line->calculation;
        $this->assertNotNull($calc);

        $snapshot = $calc->calculation_snapshot;
        $this->assertSame('calc-snapshot-v3', $snapshot['snapshot_version']);

        $input = $snapshot['input'];
        $this->assertArrayHasKey('overtime_25_cents', $input);
        $this->assertArrayHasKey('overtime_50_cents', $input);
        $this->assertArrayHasKey('overtime_daily_cents', $input);
        $this->assertArrayHasKey('overtime_25_hours', $input);
        $this->assertArrayHasKey('overtime_50_hours', $input);
        $this->assertArrayHasKey('overtime_daily_hours', $input);
        $this->assertArrayHasKey('base_hours', $input);
        $this->assertArrayHasKey('total_hours', $input);
        $this->assertArrayHasKey('base_salary_cents', $input);
        $this->assertArrayHasKey('overtime_cents', $input);
    }

    // ═══════════════════════════════════════════════════════
    // Backward compatibility
    // ═══════════════════════════════════════════════════════

    public function test_old_gross_breakdown_keys_still_present(): void
    {
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        // All original keys must still exist
        $requiredKeys = [
            'base_salary_cents', 'overtime_cents', 'unpaid_leave_deduction_cents',
            'gross_basis_cents', 'formula_version', 'hourly_rate_cents',
            'overtime_hours', 'overtime_multiplier', 'unpaid_leave_days', 'daily_rate_cents',
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $gb, "Missing original key: {$key}");
        }
    }

    public function test_formula_version_unchanged(): void
    {
        $this->createTimesheetWithOvertime(300);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $this->assertSame(PayrollLine::FORMULA_VERSION, $line->gross_breakdown['formula_version']);
    }

    // ═══════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════

    public function test_all_daily_overtime_no_weekly(): void
    {
        // Total overtime = 120 min (2h), all from daily
        $this->createTimesheetWithOvertime(120, [
            '2026-05-05' => 60,
            '2026-05-12' => 60,
        ]);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(0.0, $gb['overtime_25_hours']);
        $this->assertEquals(0.0, $gb['overtime_50_hours']);
        $this->assertEquals(2.0, $gb['overtime_daily_hours']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
    }

    public function test_very_high_overtime_stress(): void
    {
        // 20h overtime: daily 5h + weekly 15h (8h HS25 + 7h HS50)
        $this->createTimesheetWithOvertime(1200, [
            '2026-05-05' => 60,
            '2026-05-06' => 60,
            '2026-05-07' => 60,
            '2026-05-12' => 60,
            '2026-05-13' => 60,
        ]);
        $run = $this->createDraftRun();
        $line = $this->computeAndGetLine($run);

        $gb = $line->gross_breakdown;

        $this->assertEquals(5.0, $gb['overtime_daily_hours']);
        $this->assertEquals(8.0, $gb['overtime_25_hours']);
        $this->assertEquals(7.0, $gb['overtime_50_hours']);

        $ventilatedSum = $gb['overtime_25_cents'] + $gb['overtime_50_cents'] + $gb['overtime_daily_cents'];
        $this->assertSame($gb['overtime_cents'], $ventilatedSum);
        $this->assertSame($gb['gross_basis_cents'], $gb['base_salary_cents'] + $gb['overtime_cents'] - $gb['unpaid_leave_deduction_cents']);
    }
}
