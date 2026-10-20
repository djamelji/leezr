<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT F — server-side planning validations wired to the normative WorkforceRuleResolver.
 * Proves daily rest between working days, daily/weekly maximum hours, night-work detection,
 * split-shift tolerance, and graceful skip when a legal dimension is undocumented.
 */
class WorkforcePlanningRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;
    private CreateShiftUseCase $create;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);

        $this->company = Company::create(['name' => 'Rules Co', 'slug' => 'rules-co', 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        $this->employee = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'R', 'last_name' => 'C', 'status' => 'active', 'hire_date' => '2024-01-01']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01']);
        $this->create = app(CreateShiftUseCase::class);
    }

    private function shift(string $date, string $start, string $end): Shift
    {
        return $this->create->execute($this->company->id, $this->employee->id, $date, "{$date} {$start}:00", "{$date} {$end}:00", 'Europe/Paris');
    }

    public function test_daily_rest_between_working_days_is_enforced(): void
    {
        // 2026-03-02 ends 16:00 ; 2026-03-03 starts 02:00 → 10h gap < legal 11h.
        $this->shift('2026-03-02', '08:00', '16:00');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Insufficient daily rest');
        $this->shift('2026-03-03', '02:00', '06:00');
    }

    public function test_split_shifts_same_day_are_allowed_despite_rest_rule(): void
    {
        // Two vacations the same day (08-12, 13-17) — the 11h daily rest must NOT apply within a day.
        $this->shift('2026-03-02', '08:00', '12:00');
        $second = $this->shift('2026-03-02', '13:00', '17:00');
        $this->assertNotNull($second->id);
    }

    public function test_daily_maximum_hours_is_enforced(): void
    {
        // 09:00 → 22:00 = 13h worked > legal daily max 10h.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Daily working time exceeds');
        $this->shift('2026-03-02', '09:00', '22:00');
    }

    public function test_weekly_maximum_hours_is_enforced(): void
    {
        // Mon–Thu 10h/day = 40h (each ≤ daily max, 14h rest between days). Fri 10h → 50h > legal 48h.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $d) {
            $this->shift($d, '08:00', '18:00');
        }
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Weekly working time exceeds');
        $this->shift('2026-03-06', '08:00', '18:00');
    }

    public function test_night_work_is_flagged_when_window_configured(): void
    {
        $resolver = app(WorkforceRuleResolver::class);
        $resolver->setWorkTimeRule($this->company, 'night_work_start', '21:00');
        $resolver->setWorkTimeRule($this->company, 'night_work_end', '06:00');

        // Shift dates must be on/after the config effective date (set "today") for the window to apply.
        $night = $this->shift('2026-12-01', '22:00', '23:30');
        $this->assertTrue($night->metadata['night_work'], 'shift inside the night window is flagged');

        $day = $this->shift('2026-12-03', '09:00', '12:00');
        $this->assertFalse($day->metadata['night_work'], 'daytime shift is not flagged');
    }

    public function test_undocumented_rule_does_not_block(): void
    {
        // A company on a market without these legal rules resolves them to_complete → checks skip.
        $bare = Company::create(['name' => 'Bare Co', 'slug' => 'bare-co', 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'GB']);
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $bare->id, 'first_name' => 'B', 'last_name' => 'C', 'status' => 'active', 'hire_date' => '2024-01-01']);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $bare->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01']);

        // 13h shift would breach a 10h daily max — but on market ZZ the max is undocumented, so it is allowed.
        $shift = app(CreateShiftUseCase::class)->execute($bare->id, $emp->id, '2026-03-02', '2026-03-02 09:00:00', '2026-03-02 22:00:00', 'Europe/Paris');
        $this->assertNotNull($shift->id);
    }
}
