<?php

namespace App\Console\Commands;

use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): give the demo employee Bob (employee #3,
 * company #1) a coherent annual-leave accrual so the leave-request workflow is testable
 * end to end. Bob already has an active contract (seeded). Idempotent via idempotency_key.
 *
 * NOT for production — guarded by environment. Reproducible for the E2E campaign.
 */
class E2EPrepareLeaveFixtureCommand extends Command
{
    protected $signature = 'e2e:prepare-leave-fixture {--employee=3} {--leave-type=1} {--days=25}';

    protected $description = 'Seed a coherent leave balance for an E2E demo employee (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $companyId = 1;
        $employeeId = (int) $this->option('employee');
        $leaveTypeId = (int) $this->option('leave-type');
        $hundredths = (int) $this->option('days') * 100;
        $year = (string) now()->year;

        LeaveLedger::credit(
            companyId: $companyId,
            employeeId: $employeeId,
            leaveTypeId: $leaveTypeId,
            amount: $hundredths,
            entryType: 'accrual',
            periodKey: $year,
            correlationId: 'e2e-leave-fixture',
            idempotencyKey: "e2e-leave-accrual-{$employeeId}-{$leaveTypeId}-{$year}",
            description: 'E2E fixture: annual leave accrual',
        );

        $balance = LeaveLedger::computeBalance($companyId, $employeeId, $leaveTypeId);
        $this->info("Employee #{$employeeId} leave balance (type {$leaveTypeId}): available={$balance['available']} hundredths.");

        return self::SUCCESS;
    }
}
