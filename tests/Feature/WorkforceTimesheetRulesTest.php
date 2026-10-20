<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use App\Modules\Workforce\UseCases\LockTimesheetUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT H — timesheet generation wired to the normative WorkforceRuleResolver:
 * effective daily-overtime threshold, paid breaks counted as worktime, night-hour buckets,
 * and lock-time freeze of the effective rules (locked periods are not silently recomputed).
 */
class WorkforceTimesheetRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);

        Model::unguard();
        $this->company = Company::create(['name' => 'TS Co', 'slug' => 'ts-co', 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'first_name' => 'T', 'last_name' => 'S', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        Model::reguard();
    }

    private function entry(string $date, string $in, string $out, int $worked, int $break = 0): TimeEntry
    {
        Model::unguard();
        $e = TimeEntry::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'date' => $date,
            'clock_in' => "{$date} {$in}:00", 'clock_out' => "{$date} {$out}:00", 'status' => TimeEntry::STATUS_COMPLETED,
            'total_worked_minutes' => $worked, 'total_break_minutes' => $break, 'source' => 'manual',
        ]);
        Model::reguard();

        return $e;
    }

    private function generate(string $date): TimesheetPeriod
    {
        $end = \Carbon\Carbon::parse($date)->addDay()->toDateString();

        return app(GenerateTimesheetUseCase::class)->execute($this->company->id, $this->employee->id, $date, $end);
    }

    private function lineFor(TimesheetPeriod $period, string $date)
    {
        return $period->lines()->whereDate('date', $date)->first();
    }

    public function test_effective_daily_overtime_threshold_drives_overtime(): void
    {
        // Resolver threshold 6h (360') — distinct from the weekly/5 = 7h fallback (420').
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'overtime_daily_threshold_hours', 6);

        $this->entry('2026-11-02', '08:00', '17:00', 540); // 9h worked
        $line = $this->lineFor($this->generate('2026-11-02'), '2026-11-02');

        $this->assertSame(180, $line->daily_overtime_minutes, '540 - 360 (effective 6h) = 180');
    }

    public function test_paid_break_is_classified_from_the_effective_rule(): void
    {
        // Default break rule (unpaid, excluded) → the break is NOT classified as paid worktime.
        $this->entry('2026-11-03', '08:00', '16:00', 420, 60);
        $baseline = $this->lineFor($this->generate('2026-11-03'), '2026-11-03');
        $this->assertSame(0, $baseline->metadata['paid_break_minutes']);
        $this->assertFalse($baseline->metadata['break_included_in_worktime']);

        // A different employee whose break rule counts breaks in worktime → classified as paid.
        app(WorkforceRuleResolver::class)->setBreakRule($this->company, 'included_in_worktime', true);
        Model::unguard();
        $emp2 = Employee::create(['company_id' => $this->company->id, 'first_name' => 'P', 'last_name' => 'B', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $emp2->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        TimeEntry::create(['company_id' => $this->company->id, 'employee_id' => $emp2->id, 'date' => '2026-11-03', 'clock_in' => '2026-11-03 08:00:00', 'clock_out' => '2026-11-03 16:00:00', 'status' => TimeEntry::STATUS_COMPLETED, 'total_worked_minutes' => 420, 'total_break_minutes' => 60, 'source' => 'manual']);
        Model::reguard();

        $period2 = app(GenerateTimesheetUseCase::class)->execute($this->company->id, $emp2->id, '2026-11-03', '2026-11-04');
        $after = $period2->lines()->whereDate('date', '2026-11-03')->first();

        $this->assertTrue($after->metadata['break_included_in_worktime']);
        $this->assertGreaterThan(0, $after->metadata['paid_break_minutes']);
    }

    public function test_night_minutes_are_computed_from_the_effective_window(): void
    {
        $resolver = app(WorkforceRuleResolver::class);
        $resolver->setWorkTimeRule($this->company, 'night_work_start', '21:00');
        $resolver->setWorkTimeRule($this->company, 'night_work_end', '06:00');

        $this->entry('2026-11-02', '20:00', '23:30', 210); // 21:00→23:30 inside night = 150'
        $line = $this->lineFor($this->generate('2026-11-02'), '2026-11-02');

        $this->assertSame(150, $line->metadata['night_minutes']);
    }

    public function test_lock_freezes_effective_rules_and_locked_period_is_immutable(): void
    {
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce_payroll', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $approver = \App\Core\Models\User::factory()->create();

        $this->entry('2026-11-02', '08:00', '16:00', 420);
        $period = $this->generate('2026-11-02');
        $period->transitionTo(TimesheetPeriod::STATUS_SUBMITTED);
        $period->save();
        $period->transitionTo(TimesheetPeriod::STATUS_APPROVED);
        $period->save();

        $locked = app(LockTimesheetUseCase::class)->execute($period, $approver->id);

        $this->assertArrayHasKey('workforce_rules', $locked->policy_snapshot);
        $this->assertArrayHasKey('work_time', $locked->policy_snapshot['workforce_rules']);
        $this->assertArrayHasKey('breaks', $locked->policy_snapshot['workforce_rules']);

        // A later rule change must not recompute a locked period: regenerating it is refused.
        app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company, 'overtime_daily_threshold_hours', 4);
        $this->expectException(\DomainException::class);
        $this->generate('2026-11-02');
    }
}
