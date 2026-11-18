<?php

namespace Tests\Feature;

use App\Console\Recipe\RecipeDatabaseGuard;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AllowingRecipeGuard;
use Tests\TestCase;

/**
 * ADR-829 (D-21) — e2e:reset-clock-day --clear-open-days : the clock replay precondition « no open day ».
 * Removes ONLY earlier days left open of THAT employee ; closed days and other employees are untouched.
 * The guard is still the real one outside this test (ADR-827) : refused here without the allowing double.
 */
class Adr829ResetClockDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_open_days_removes_only_earlier_open_days_of_that_employee(): void
    {
        Carbon::setTestNow('2026-11-18 09:00:00');
        Model::unguard();
        \App\Core\Jobdomains\Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        \App\Core\Markets\Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $company = Company::create(['name' => 'Clk', 'slug' => 'clk829', 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        $e = Employee::create(['company_id' => $company->id, 'first_name' => 'B', 'last_name' => 'O', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        $other = Employee::create(['company_id' => $company->id, 'first_name' => 'O', 'last_name' => 'T', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        $entry = fn ($emp, $date, $out, $status) => TimeEntry::create(['company_id' => $company->id, 'employee_id' => $emp->id, 'date' => $date,
            'clock_in' => "$date 08:00:00", 'clock_out' => $out ? "$date 16:00:00" : null, 'status' => $status, 'source' => 'manual']);
        $stale = $entry($e, '2026-10-20', false, 'on_break');
        $closed = $entry($e, '2026-10-19', true, TimeEntry::STATUS_COMPLETED);
        $today = $entry($e, '2026-11-18', false, 'working');
        $otherOpen = $entry($other, '2026-10-20', false, 'working');
        Model::reguard();

        $this->assertSame(1, Artisan::call('e2e:reset-clock-day', ['--employee' => $e->id, '--clear-open-days' => true]), 'refused by the real guard');
        $this->assertSame(4, TimeEntry::withoutGlobalScopes()->count());

        $this->app->instance(RecipeDatabaseGuard::class, new AllowingRecipeGuard);
        $this->assertSame(0, Artisan::call('e2e:reset-clock-day', ['--employee' => $e->id]));
        $this->assertNull(TimeEntry::withoutGlobalScopes()->find($today->id), 'today removed');
        $this->assertNotNull(TimeEntry::withoutGlobalScopes()->find($stale->id), 'without the option, earlier days stay');

        $this->assertSame(0, Artisan::call('e2e:reset-clock-day', ['--employee' => $e->id, '--clear-open-days' => true]));
        $this->assertNull(TimeEntry::withoutGlobalScopes()->find($stale->id), 'earlier open day removed');
        $this->assertNotNull(TimeEntry::withoutGlobalScopes()->find($closed->id), 'closed day kept');
        $this->assertNotNull(TimeEntry::withoutGlobalScopes()->find($otherOpen->id), 'other employee untouched');
        Carbon::setTestNow();
    }
}
