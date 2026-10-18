<?php

namespace App\Console\Commands;

use App\Core\Workforce\LeaveBalanceCache;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Services\LeaveBalanceCacheHealer;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): remove an employee's E2E leave requests and their
 * ledger entries, then re-heal the balance cache so the leave-ledger workflow is replayable
 * without a global migrate:fresh. Targets ONLY requests whose reason starts with the E2E marker
 * so real/seeded data is untouched. Does NOT bypass leave rules — the test still drives the real
 * request/approve/reject use cases afterwards.
 *
 * NOT for production — guarded by environment.
 */
class E2EResetLeaveCommand extends Command
{
    protected $signature = 'e2e:reset-leave {--employee=3} {--marker=E2E}';

    protected $description = "Remove an employee's E2E leave requests + ledger entries and re-heal the cache (local/testing only)";

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $employeeId = (int) $this->option('employee');
        $marker = (string) $this->option('marker');

        $requests = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('reason', 'like', $marker.'%')
            ->get();

        $companyId = $requests->first()->company_id ?? 1;
        $affected = $requests->pluck('leave_type_id')->unique()->values();
        $ids = $requests->pluck('id')->all();

        if (! empty($ids)) {
            LeaveLedgerEntry::withoutGlobalScopes()
                ->where('reference_type', 'leave_request')
                ->whereIn('reference_id', $ids)
                ->delete();
            LeaveRequest::withoutGlobalScopes()->whereIn('id', $ids)->delete();
        }

        $year = (int) now()->year;
        foreach ($affected as $leaveTypeId) {
            $computed = LeaveLedger::computeBalance($companyId, $employeeId, $leaveTypeId, $year);
            LeaveBalanceCacheHealer::healIfNeeded($companyId, $employeeId, $leaveTypeId, $year, $computed);
        }

        $this->info(sprintf(
            'Reset leave for employee #%d (marker "%s"): %d request(s) removed, %d balance(s) re-healed.',
            $employeeId, $marker, count($ids), $affected->count(),
        ));

        return self::SUCCESS;
    }
}
