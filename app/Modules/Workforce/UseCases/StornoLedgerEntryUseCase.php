<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Str;

class StornoLedgerEntryUseCase
{
    /**
     * Create an exact inverse (storno) of an existing ledger entry.
     *
     * - Swaps credit ↔ debit
     * - Uses the SAME correlation_id as the original entry
     * - Generates a NEW correlation_id for the storno entry itself
     */
    public function execute(
        LeaveLedgerEntry $originalEntry,
        ?string $reason = null,
        ?int $actorId = null,
    ): LeaveLedgerEntry {
        $stornoCorrelationId = (string) Str::uuid();

        // Inverse: swap credit and debit
        $isCredit = $originalEntry->credit > 0;

        if ($isCredit) {
            // Original was credit → storno is debit
            $entry = LeaveLedger::debit(
                companyId: $originalEntry->company_id,
                employeeId: $originalEntry->employee_id,
                leaveTypeId: $originalEntry->leave_type_id,
                amount: $originalEntry->credit,
                entryType: LeaveLedgerEntry::TYPE_STORNO,
                periodKey: $originalEntry->period_key,
                correlationId: $stornoCorrelationId,
                referenceType: 'leave_ledger_entry',
                referenceId: $originalEntry->id,
                description: $reason ?? "Storno of ledger entry #{$originalEntry->id}",
                actorType: $actorId ? 'user' : 'system',
                actorId: $actorId,
                metadata: [
                    'original_entry_id' => $originalEntry->id,
                    'original_correlation_id' => $originalEntry->correlation_id,
                    'original_entry_type' => $originalEntry->entry_type,
                ],
                auditCategory: 'workforce.leave.ledger',
            );
        } else {
            // Original was debit → storno is credit
            $entry = LeaveLedger::credit(
                companyId: $originalEntry->company_id,
                employeeId: $originalEntry->employee_id,
                leaveTypeId: $originalEntry->leave_type_id,
                amount: $originalEntry->debit,
                entryType: LeaveLedgerEntry::TYPE_STORNO,
                periodKey: $originalEntry->period_key,
                correlationId: $stornoCorrelationId,
                referenceType: 'leave_ledger_entry',
                referenceId: $originalEntry->id,
                description: $reason ?? "Storno of ledger entry #{$originalEntry->id}",
                actorType: $actorId ? 'user' : 'system',
                actorId: $actorId,
                metadata: [
                    'original_entry_id' => $originalEntry->id,
                    'original_correlation_id' => $originalEntry->correlation_id,
                    'original_entry_type' => $originalEntry->entry_type,
                ],
                auditCategory: 'workforce.leave.ledger',
            );
        }

        app(AuditLogger::class)->logCompany(
            companyId: $originalEntry->company_id,
            action: 'leave_ledger.storno',
            targetType: 'leave_ledger_entry',
            targetId: (string) $entry->id,
            options: [
                'severity' => 'warning',
                'metadata' => [
                    'category' => 'workforce.leave.ledger',
                    'employee_id' => $originalEntry->employee_id,
                    'leave_type_id' => $originalEntry->leave_type_id,
                    'original_entry_id' => $originalEntry->id,
                    'original_correlation_id' => $originalEntry->correlation_id,
                    'storno_correlation_id' => $stornoCorrelationId,
                    'reason' => $reason,
                ],
            ],
        );

        return $entry;
    }
}
