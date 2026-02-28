<?php

namespace App\Console\Commands;

use App\Core\Billing\LedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;

/**
 * ADR-142 D3f: Validate ledger integrity.
 *
 * Checks:
 *   1. Double-entry invariant: SUM(debit) == SUM(credit) per correlation_id
 *   2. No orphan references (reference_type/id points to existing record)
 *   3. No currency mismatch within a correlation group
 */
class BillingLedgerCheckCommand extends Command implements Isolatable
{
    protected $signature = 'billing:ledger-check {--company= : Limit to a specific company ID}';

    protected $description = 'Validate financial ledger integrity (double-entry, orphans, currency)';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $violations = [];

        $this->info('Running ledger integrity checks...');

        // Check 1: Double-entry balance per correlation_id
        $query = LedgerEntry::selectRaw(
            'correlation_id, SUM(debit) as total_debit, SUM(credit) as total_credit'
        )->groupBy('correlation_id');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $imbalanced = $query->havingRaw('ROUND(SUM(debit), 2) != ROUND(SUM(credit), 2)')->get();

        foreach ($imbalanced as $row) {
            $violations[] = [
                'type' => 'double_entry_imbalance',
                'correlation_id' => $row->correlation_id,
                'total_debit' => $row->total_debit,
                'total_credit' => $row->total_credit,
            ];
        }

        // Check 2: Currency mismatch within correlation group
        $mixedCurrency = LedgerEntry::selectRaw('correlation_id, COUNT(DISTINCT currency) as currency_count')
            ->groupBy('correlation_id')
            ->havingRaw('COUNT(DISTINCT currency) > 1');

        if ($companyId) {
            $mixedCurrency->where('company_id', $companyId);
        }

        foreach ($mixedCurrency->get() as $row) {
            $violations[] = [
                'type' => 'currency_mismatch',
                'correlation_id' => $row->correlation_id,
                'currency_count' => $row->currency_count,
            ];
        }

        // Check 3: Orphan references
        $referenceTypes = ['invoice', 'payment', 'credit_note'];
        $tableMap = [
            'invoice' => 'invoices',
            'payment' => 'payments',
            'credit_note' => 'credit_notes',
        ];

        foreach ($referenceTypes as $refType) {
            $table = $tableMap[$refType];

            $orphanQuery = LedgerEntry::where('reference_type', $refType);

            if ($companyId) {
                $orphanQuery->where('company_id', $companyId);
            }

            $orphans = $orphanQuery
                ->whereNotExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from($table)
                        ->whereColumn("{$table}.id", 'financial_ledger_entries.reference_id');
                })
                ->select('id', 'correlation_id', 'reference_type', 'reference_id')
                ->get();

            foreach ($orphans as $orphan) {
                $violations[] = [
                    'type' => 'orphan_reference',
                    'ledger_entry_id' => $orphan->id,
                    'correlation_id' => $orphan->correlation_id,
                    'reference_type' => $orphan->reference_type,
                    'reference_id' => $orphan->reference_id,
                ];
            }
        }

        // Report
        if (count($violations) === 0) {
            $this->info('Ledger integrity check passed — no violations found.');

            return self::SUCCESS;
        }

        $this->error(count($violations) . ' violation(s) found:');

        foreach ($violations as $v) {
            $this->line("  [{$v['type']}] " . json_encode($v));
        }

        return self::FAILURE;
    }
}
