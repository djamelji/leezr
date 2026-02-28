<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\FinancialForensicsService;
use App\Core\Billing\FinancialPeriod;
use App\Core\Billing\FinancialSnapshot;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\LedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ADR-144 D4b: Read model for platform financial governance HTTP layer.
 *
 * Delegates to Core services — never duplicates business logic.
 */
class PlatformFinancialReadService
{
    public static function trialBalance(int $companyId): array
    {
        // Enrich the flat balance map into a structured response for the UI
        $rows = LedgerEntry::where('company_id', $companyId)
            ->selectRaw('account_code, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('account_code')
            ->get();

        $accounts = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $row) {
            $debit = round((float) $row->total_debit, 2);
            $credit = round((float) $row->total_credit, 2);
            $accounts[] = [
                'account_code' => $row->account_code,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => round($debit - $credit, 2),
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        // Ensure all standard accounts are present (only when ledger has data)
        if (count($accounts) > 0) {
            $present = array_column($accounts, 'account_code');

            foreach (['AR', 'CASH', 'REVENUE', 'REFUND', 'BAD_DEBT'] as $code) {
                if (! in_array($code, $present)) {
                    $accounts[] = [
                        'account_code' => $code,
                        'total_debit' => 0.0,
                        'total_credit' => 0.0,
                        'balance' => 0.0,
                    ];
                }
            }
        }

        // Derive currency from ledger entries (single-currency invariant)
        $currencies = LedgerEntry::where('company_id', $companyId)
            ->distinct()
            ->pluck('currency')
            ->filter()
            ->values();

        if ($currencies->count() > 1) {
            throw new \RuntimeException(
                "Mixed currencies in ledger for company {$companyId}: " . $currencies->implode(', ')
            );
        }

        return [
            'accounts' => $accounts,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'net_balance' => round($totalDebit - $totalCredit, 2),
            'currency' => $currencies->first(),
        ];
    }

    public static function ledgerEntries(
        int $companyId,
        ?string $correlationId = null,
        ?string $entryType = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        $query = LedgerEntry::where('company_id', $companyId)
            ->orderByDesc('recorded_at');

        if ($correlationId) {
            $query->where('correlation_id', $correlationId);
        }

        if ($entryType) {
            $query->where('entry_type', $entryType);
        }

        return $query->paginate($perPage);
    }

    public static function financialPeriods(int $companyId): Collection
    {
        return FinancialPeriod::where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->get();
    }

    public static function forensicsTimeline(
        int $companyId,
        int $days = 30,
        ?string $entityType = null,
    ): array {
        return FinancialForensicsService::timeline($companyId, $days, $entityType);
    }

    public static function forensicsSnapshots(int $companyId, int $perPage = 50): LengthAwarePaginator
    {
        return FinancialSnapshot::where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public static function driftHistory(?int $companyId = null, int $limit = 50): Collection
    {
        $query = PlatformAuditLog::where('action', 'billing.drift_detected')
            ->orderByDesc('created_at')
            ->limit($limit);

        $logs = $query->get();

        if ($companyId) {
            return $logs->filter(function ($log) use ($companyId) {
                return ($log->metadata['company_id'] ?? null) == $companyId;
            })->values();
        }

        return $logs;
    }
}
