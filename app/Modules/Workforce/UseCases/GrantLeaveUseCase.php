<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Str;

class GrantLeaveUseCase
{
    /**
     * Grant exceptional leave credit to an employee.
     *
     * For seniority bonuses, management grants, etc.
     * Creates a grant credit entry in the ledger.
     */
    public function execute(
        Employee $employee,
        int $leaveTypeId,
        int $amountHundredths,
        string $periodKey,
        ?string $description = null,
        ?int $actorId = null,
    ): LeaveLedgerEntry {
        $correlationId = (string) Str::uuid();

        $entry = LeaveLedger::credit(
            companyId: $employee->company_id,
            employeeId: $employee->id,
            leaveTypeId: $leaveTypeId,
            amount: $amountHundredths,
            entryType: LeaveLedgerEntry::TYPE_GRANT,
            periodKey: $periodKey,
            correlationId: $correlationId,
            description: $description ?? "Exceptional leave grant for {$employee->full_name}",
            actorType: $actorId ? 'user' : 'system',
            actorId: $actorId,
            auditCategory: 'workforce.leave.ledger',
        );

        app(AuditLogger::class)->logCompany(
            companyId: $employee->company_id,
            action: 'leave_ledger.grant',
            targetType: 'leave_ledger_entry',
            targetId: (string) $entry->id,
            options: [
                'severity' => 'warning',
                'metadata' => [
                    'category' => 'workforce.leave.ledger',
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveTypeId,
                    'amount_hundredths' => $amountHundredths,
                    'period_key' => $periodKey,
                    'correlation_id' => $correlationId,
                ],
            ],
        );

        return $entry;
    }
}
