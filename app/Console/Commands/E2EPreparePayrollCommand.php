<?php

namespace App\Console\Commands;

use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): ensure a LOCKED timesheet exists for an employee over a
 * period, so a payroll run can compute a real line for that monthly-salaried employee (they
 * already have an active compensation plan). This is test-data PREPARATION — it does NOT bypass
 * the payroll engine: ComputePayrollUseCase still runs its real contribution/tax calculations.
 *
 * NOT for production — guarded by environment.
 */
class E2EPreparePayrollCommand extends Command
{
    protected $signature = 'e2e:prepare-payroll {--employee=3} {--company=1} {--from=} {--to=} {--minutes=9240}';

    protected $description = 'Ensure a locked timesheet for an employee over a period (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $employeeId = (int) $this->option('employee');
        $companyId = (int) $this->option('company');
        $from = $this->option('from') ?: today()->startOfMonth()->toDateString();
        $to = $this->option('to') ?: today()->endOfMonth()->toDateString();
        $minutes = (int) $this->option('minutes');

        // Clear any existing period in the window first (idempotent).
        TimesheetPeriod::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->each(function (TimesheetPeriod $p) {
                $p->lines()->delete();
                $p->delete();
            });

        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'period_start' => $from,
            'period_end' => $to,
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => $minutes,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => $minutes,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
        ]);

        $this->info("Prepared LOCKED timesheet #{$period->id} for employee #{$employeeId} in [{$from} … {$to}].");

        return self::SUCCESS;
    }
}
