<?php

namespace App\Console\Commands;

use App\Core\Workforce\PayrollRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E2E test fixture (LOCAL/TESTING only): delete payroll runs (and their lines/calculations)
 * overlapping a date range so the payroll workflow (create → compute → validate → export) is
 * replayable without a global migrate:fresh. Clears only the test window; does NOT bypass any
 * payroll rule — the test drives the real run lifecycle afterwards. No payment is ever involved.
 *
 * NOT for production — guarded by environment.
 */
class E2EResetPayrollCommand extends Command
{
    protected $signature = 'e2e:reset-payroll {--company=1} {--from=} {--to=}';

    protected $description = 'Delete payroll runs overlapping a range (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $companyId = (int) $this->option('company');
        $from = $this->option('from') ?: today()->startOfMonth()->toDateString();
        $to = $this->option('to') ?: today()->endOfMonth()->toDateString();

        $runs = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->get();

        $count = 0;
        foreach ($runs as $run) {
            $lineIds = DB::table('workforce_payroll_lines')->where('payroll_run_id', $run->id)->pluck('id');
            if ($lineIds->isNotEmpty()) {
                DB::table('workforce_payroll_calculations')->whereIn('payroll_line_id', $lineIds)->delete();
            }
            DB::table('workforce_payroll_lines')->where('payroll_run_id', $run->id)->delete();
            $run->delete();
            $count++;
        }

        $this->info("Reset payroll for company #{$companyId} in [{$from} … {$to}]: {$count} run(s) removed.");

        return self::SUCCESS;
    }
}
