<?php

namespace App\Core\Workforce\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveBalanceCache;
use App\Core\Workforce\LeaveLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Leave ledger — credit/debit operations with SELECT FOR UPDATE locking.
 *
 * Source of truth: leave_ledger_entries.
 * LeaveBalanceCache is recomputed (self-healed) on every write within the lock.
 *
 * Invariants:
 *   - cached balances = recomputed from ledger entries on every write
 *   - Idempotency via idempotency_key (duplicate writes are silently skipped)
 *   - All mutations within DB::transaction + lockForUpdate
 */
class LeaveLedger
{
    /**
     * Write a credit entry to the leave ledger.
     */
    public static function credit(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $amount,
        string $entryType,
        string $periodKey,
        string $correlationId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
        ?string $description = null,
        string $actorType = 'system',
        ?int $actorId = null,
        ?array $metadata = null,
        ?string $auditCategory = 'workforce.leave.ledger',
    ): LeaveLedgerEntry {
        return self::record(
            companyId: $companyId,
            employeeId: $employeeId,
            leaveTypeId: $leaveTypeId,
            credit: $amount,
            debit: 0,
            entryType: $entryType,
            periodKey: $periodKey,
            correlationId: $correlationId,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
            description: $description,
            actorType: $actorType,
            actorId: $actorId,
            metadata: $metadata,
            auditCategory: $auditCategory,
        );
    }

    /**
     * Write a debit entry to the leave ledger.
     */
    public static function debit(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $amount,
        string $entryType,
        string $periodKey,
        string $correlationId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
        ?string $description = null,
        string $actorType = 'system',
        ?int $actorId = null,
        ?array $metadata = null,
        ?string $auditCategory = 'workforce.leave.ledger',
    ): LeaveLedgerEntry {
        return self::record(
            companyId: $companyId,
            employeeId: $employeeId,
            leaveTypeId: $leaveTypeId,
            credit: 0,
            debit: $amount,
            entryType: $entryType,
            periodKey: $periodKey,
            correlationId: $correlationId,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
            description: $description,
            actorType: $actorType,
            actorId: $actorId,
            metadata: $metadata,
            auditCategory: $auditCategory,
        );
    }

    /**
     * Compute the typed balance breakdown from the ledger (source of truth).
     */
    public static function computeBalance(int $companyId, int $employeeId, int $leaveTypeId, ?int $year = null): array
    {
        $query = LeaveLedgerEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId);

        if ($year) {
            $query->where('period_key', 'LIKE', "{$year}%");
        }

        $entries = $query->selectRaw("
            entry_type,
            SUM(credit) as total_credit,
            SUM(debit) as total_debit
        ")->groupBy('entry_type')->get();

        $accrued = 0;
        $reserved = 0;
        $consumed = 0;
        $adjusted = 0;

        foreach ($entries as $row) {
            switch ($row->entry_type) {
                case LeaveLedgerEntry::TYPE_ACCRUAL:
                case LeaveLedgerEntry::TYPE_GRANT:
                case LeaveLedgerEntry::TYPE_CARRY_OVER:
                    $accrued += (int) $row->total_credit;
                    break;
                case LeaveLedgerEntry::TYPE_RESERVATION:
                    $reserved += (int) $row->total_debit;
                    break;
                case LeaveLedgerEntry::TYPE_RELEASE:
                    $reserved -= (int) $row->total_credit;
                    break;
                case LeaveLedgerEntry::TYPE_CONSUMPTION:
                    $consumed += (int) $row->total_debit;
                    break;
                case LeaveLedgerEntry::TYPE_ADJUSTMENT:
                case LeaveLedgerEntry::TYPE_STORNO:
                    $adjusted += (int) $row->total_credit - (int) $row->total_debit;
                    break;
            }
        }

        $available = $accrued - $reserved - $consumed + $adjusted;

        return [
            'accrued' => $accrued,
            'reserved' => $reserved,
            'consumed' => $consumed,
            'adjusted' => $adjusted,
            'available' => $available,
        ];
    }

    /**
     * Core record method — all ledger writes go through here.
     *
     * Runs inside DB::transaction with SELECT FOR UPDATE on the balance cache row.
     * Computes balance_after from the ledger (source of truth), not from cache.
     * Self-heals cache after every write.
     */
    private static function record(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $credit,
        int $debit,
        string $entryType,
        string $periodKey,
        string $correlationId,
        ?string $referenceType,
        ?int $referenceId,
        ?string $idempotencyKey,
        ?string $description,
        string $actorType,
        ?int $actorId,
        ?array $metadata,
        string $auditCategory,
    ): LeaveLedgerEntry {
        if (! in_array($entryType, LeaveLedgerEntry::ENTRY_TYPES, true)) {
            throw new \DomainException("Invalid ledger entry type: {$entryType}");
        }

        return DB::transaction(function () use (
            $companyId, $employeeId, $leaveTypeId, $credit, $debit,
            $entryType, $periodKey, $correlationId, $referenceType, $referenceId,
            $idempotencyKey, $description, $actorType, $actorId, $metadata, $auditCategory,
        ) {
            // Idempotency check
            if ($idempotencyKey !== null) {
                $existing = LeaveLedgerEntry::withoutCompanyScope()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            // Lock the balance cache row (or create it)
            $year = (int) substr($periodKey, 0, 4);
            $balanceCache = LeaveBalanceCache::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('period_year', $year)
                ->lockForUpdate()
                ->first();

            if (! $balanceCache) {
                $balanceCache = LeaveBalanceCache::create([
                    'company_id' => $companyId,
                    'employee_id' => $employeeId,
                    'leave_type_id' => $leaveTypeId,
                    'period_year' => $year,
                    'cached_accrued' => 0,
                    'cached_reserved' => 0,
                    'cached_consumed' => 0,
                    'cached_adjusted' => 0,
                    'cached_available' => 0,
                    'last_computed_at' => now(),
                ]);
                // Re-lock after create
                $balanceCache = LeaveBalanceCache::where('id', $balanceCache->id)->lockForUpdate()->first();
            }

            // Compute current balance from ledger (source of truth)
            $computed = self::computeBalance($companyId, $employeeId, $leaveTypeId, $year);
            $balanceAfter = $computed['available'] + $credit - $debit;

            // Create immutable ledger entry
            $entry = LeaveLedgerEntry::create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'entry_type' => $entryType,
                'credit' => $credit,
                'debit' => $debit,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'correlation_id' => $correlationId,
                'period_key' => $periodKey,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'recorded_at' => now(),
                'metadata' => $metadata,
            ]);

            // Self-heal cache (recompute after the new entry)
            $newComputed = self::computeBalance($companyId, $employeeId, $leaveTypeId, $year);
            $balanceCache->update([
                'cached_accrued' => $newComputed['accrued'],
                'cached_reserved' => $newComputed['reserved'],
                'cached_consumed' => $newComputed['consumed'],
                'cached_adjusted' => $newComputed['adjusted'],
                'cached_available' => $newComputed['available'],
                'last_computed_at' => now(),
            ]);

            // Audit logging
            $severity = in_array($entryType, [
                LeaveLedgerEntry::TYPE_GRANT,
                LeaveLedgerEntry::TYPE_STORNO,
                LeaveLedgerEntry::TYPE_ADJUSTMENT,
            ]) ? 'warning' : 'info';

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: "leave_ledger.{$entryType}",
                targetType: 'leave_ledger_entry',
                targetId: (string) $entry->id,
                options: [
                    'severity' => $severity,
                    'metadata' => [
                        'category' => $auditCategory,
                        'employee_id' => $employeeId,
                        'leave_type_id' => $leaveTypeId,
                        'credit' => $credit,
                        'debit' => $debit,
                        'balance_after' => $balanceAfter,
                        'correlation_id' => $correlationId,
                    ],
                ],
            );

            return $entry;
        });
    }
}
