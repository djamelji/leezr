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
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-826 — the payroll preparation follows the work model : what the engine cannot compute correctly is
 * excluded with an explicit reason, never prepared silently ; full-time hourly stays unchanged.
 */
class Adr826WorkModelPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris',
            'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        DB::table('market_rule_sets')->insert(['market_key' => 'FR', 'domain' => 'workforce', 'rule_key' => 'weekly_hours_legal',
            'value' => json_encode(['unit' => 'hours', 'amount' => 35]), 'effective_from' => '2020-01-01', 'source' => 'test', 'reference' => 'ADR-826 test', 'created_at' => now(), 'updated_at' => now()]);
        PlatformModule::create(['key' => 'workforce_payroll', 'name' => 'Payroll', 'is_enabled_globally' => true]);
        $this->company = Company::create(['name' => 'WM Co', 'slug' => 'wm-co-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->user = User::factory()->create();
    }

    /** @param  array<int, int>  $days  worked minutes per day starting 2026-05-04 (Monday) */
    private function employee(string $name, string $model, float $weekly, string $pay, ?array $days, int $overtime = 0): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $name, 'last_name' => 'T',
            'employee_number' => 'E-'.$name, 'hire_date' => '2024-01-01', 'status' => 'active']);
        $c = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi',
            'work_model_key' => $model, 'weekly_hours' => $weekly, 'status' => EmploymentContract::STATUS_ACTIVE, 'start_date' => '2025-01-01', 'is_current' => true]);
        CompensationPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'contract_id' => $c->id, 'compensation_type' => $pay,
            'base_salary_cents' => 300000, 'daily_rate_cents' => 20000, 'currency' => 'EUR', 'pay_frequency' => 'monthly', 'overtime_rate_bps' => 2500, 'effective_from' => '2025-01-01']);
        if ($days !== null) {
            $ts = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id,
                'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => TimesheetPeriod::STATUS_LOCKED,
                'total_worked_minutes' => array_sum($days), 'total_break_minutes' => 0, 'total_overtime_minutes' => $overtime,
                'total_planned_minutes' => array_sum($days), 'total_leave_days_hundredths' => 0, 'anomaly_count' => 0, 'locked_at' => now(), 'locked_by' => $this->user->id]);
            foreach ($days as $i => $m) {
                DB::table('workforce_timesheet_lines')->insert(['company_id' => $this->company->id, 'timesheet_period_id' => $ts->id, 'employee_id' => $e->id,
                    'date' => \Illuminate\Support\Carbon::parse('2026-05-04')->addDays($i)->toDateString(), 'worked_minutes' => $m, 'source_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $e;
    }

    private function compute(): PayrollRun
    {
        $run = PayrollRun::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'status' => PayrollRun::STATUS_DRAFT, 'currency' => 'EUR']);

        return app(ComputePayrollUseCase::class)->execute($run, $this->user->id)->fresh();
    }

    private function reason(PayrollRun $run, Employee $e): ?string
    {
        return collect($run->anomalies ?? [])->firstWhere('employee_id', $e->id)['type'] ?? null;
    }

    private function hasLine(PayrollRun $run, Employee $e): bool
    {
        return PayrollLine::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->exists();
    }

    public function test_each_work_model_is_prepared_or_excluded_with_its_reason(): void
    {
        $week = [420, 420, 420, 420, 420];
        $hourlyFull = $this->employee('HoraireOk', 'horaire', 35, 'monthly', $week, 0);
        $hourlyOt = $this->employee('HoraireHs', 'horaire', 35, 'monthly', [480, 480, 480, 480, 480], 300);
        $partOk = $this->employee('PartielOk', 'horaire', 24, 'monthly', [480, 480, 480, 0, 0]);
        $partBeyond = $this->employee('PartielPlus', 'horaire', 24, 'monthly', [480, 480, 480, 360, 0]);
        $daysOk = $this->employee('JoursOk', 'forfait_jours', 35, 'monthly', $week, 0);
        $daysOt = $this->employee('JoursHs', 'forfait_jours', 35, 'monthly', [600, 600, 600, 600, 600], 900);
        $daysHourly = $this->employee('JoursHoraire', 'forfait_jours', 35, 'hourly', $week, 0);
        $daysMissing = $this->employee('JoursSansReleve', 'forfait_jours', 35, 'monthly', null);
        $hoursOt = $this->employee('HeuresHs', 'forfait_heures', 39, 'monthly', [540, 540, 540, 540, 540], 240);
        $unknown = $this->employee('Inconnu', 'on_site', 35, 'monthly', $week, 0);
        $hourlyMissing = $this->employee('HoraireSansFeuille', 'horaire', 35, 'monthly', null);

        $run = $this->compute();

        // Prepared exactly as before
        foreach ([$hourlyFull, $hourlyOt, $partOk, $daysOk] as $e) {
            $this->assertTrue($this->hasLine($run, $e), $e->first_name.' prepared');
            $this->assertNull($this->reason($run, $e), $e->first_name.' not excluded');
        }
        // Excluded with the reason of the work model (never a silent line)
        $expected = [
            [$partBeyond, PayrollRun::ANOMALY_PART_TIME_HOURS_BEYOND_CONTRACT],
            [$daysOt, PayrollRun::ANOMALY_WORK_MODEL_OVERTIME_NOT_APPLICABLE],
            [$daysHourly, PayrollRun::ANOMALY_WORK_MODEL_COMPENSATION_MISMATCH],
            [$daysMissing, PayrollRun::ANOMALY_WORK_MODEL_TRACKING_UNSUPPORTED],
            [$hoursOt, PayrollRun::ANOMALY_WORK_MODEL_OVERTIME_NOT_QUALIFIED],
            [$unknown, PayrollRun::ANOMALY_WORK_MODEL_UNKNOWN],
            [$hourlyMissing, PayrollRun::ANOMALY_MISSING_TIMESHEET],
        ];
        foreach ($expected as [$e, $type]) {
            $this->assertSame($type, $this->reason($run, $e), $e->first_name);
            $this->assertFalse($this->hasLine($run, $e), $e->first_name.' has no line');
        }
        $beyond = collect($run->anomalies)->firstWhere('employee_id', $partBeyond->id);
        $this->assertSame(360, $beyond['minutes_beyond_contract'], '30 h worked for 24 h : 6 h beyond the contract');

        // Every exclusion is an error : validation needs a written justification (ADR-824 rule kept)
        $this->assertTrue($run->hasErrorAnomalies());
    }
}
