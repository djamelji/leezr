<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Billing Cockpit 2.0 — PRIORITY 1 : read-only financial reconciliation between the double-entry
 * ledger (source of truth for KPIs, ADR-582) and the business documents (invoices, payments,
 * credit notes). It NEVER mutates data and NEVER adjusts a KPI to hide a difference — divergences
 * are returned as explicit anomalies so an operator can explain (or investigate) every euro.
 *
 * All monetary values are INTEGER CENTS. Totals are grouped BY CURRENCY and never summed across
 * currencies (ADR-582 multi-currency guard).
 */
class FinanceReconciliationService
{
    /**
     * @return array{
     *   by_currency: array<int, array<string, mixed>>,
     *   anomalies: array<int, array<string, mixed>>,
     *   multi_currency: bool
     * }
     */
    public static function report(?int $companyId = null): array
    {
        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;

        // ── Ledger totals per currency (cents) ──
        $ledger = $scope(LedgerEntry::query())
            ->select('currency', 'account_code', DB::raw('SUM(debit) d'), DB::raw('SUM(credit) c'))
            ->groupBy('currency', 'account_code')
            ->get();

        $byCur = [];
        foreach ($ledger as $row) {
            $cur = $row->currency;
            $byCur[$cur] ??= ['currency' => $cur, 'ledger_ar' => 0, 'ledger_cash' => 0, 'ledger_revenue' => 0, 'ledger_vat' => 0, 'ledger_refund' => 0, 'ledger_bad_debt' => 0];
            $net = (int) round((float) $row->d - (float) $row->c);
            $credit = (int) round((float) $row->c);
            match ($row->account_code) {
                'AR' => $byCur[$cur]['ledger_ar'] = $net,
                'CASH' => $byCur[$cur]['ledger_cash'] = $net,
                'REVENUE' => $byCur[$cur]['ledger_revenue'] = $credit,
                'VAT_PAYABLE' => $byCur[$cur]['ledger_vat'] = $credit,
                'REFUND' => $byCur[$cur]['ledger_refund'] = (int) round((float) $row->d),
                'BAD_DEBT' => $byCur[$cur]['ledger_bad_debt'] = (int) round((float) $row->d),
                default => null,
            };
        }

        // ── Document totals per currency (cents) ──
        $invAgg = $scope(Invoice::query())
            ->where('status', '!=', 'void')
            ->select('currency', DB::raw('SUM(amount_due) due'), DB::raw('SUM(amount) billed'), DB::raw('SUM(subtotal) ht'), DB::raw('SUM(tax_amount) vat'))
            ->groupBy('currency')->get()->keyBy('currency');

        $paidAgg = $scope(Payment::query())
            ->where('status', 'succeeded')
            ->select('currency', DB::raw('SUM(amount) paid'))
            ->groupBy('currency')->get()->keyBy('currency');

        $cnAgg = $scope(CreditNote::query())
            ->where('status', 'applied')
            ->select('currency', DB::raw('SUM(amount) cn'))
            ->groupBy('currency')->get()->keyBy('currency');

        foreach (array_keys($byCur) as $cur) {
            $byCur[$cur]['invoices_outstanding'] = (int) ($invAgg[$cur]->due ?? 0);
            $byCur[$cur]['invoices_billed'] = (int) ($invAgg[$cur]->billed ?? 0);
            $byCur[$cur]['payments_succeeded'] = (int) ($paidAgg[$cur]->paid ?? 0);
            $byCur[$cur]['credit_notes_applied'] = (int) ($cnAgg[$cur]->cn ?? 0);
            // Reconciliation gaps (ledger vs documents). 0 = reconciled.
            $byCur[$cur]['gap_ar_vs_invoices'] = $byCur[$cur]['ledger_ar'] - $byCur[$cur]['invoices_outstanding'];
            $byCur[$cur]['gap_cash_vs_payments'] = $byCur[$cur]['ledger_cash'] - ($byCur[$cur]['payments_succeeded'] - $byCur[$cur]['ledger_refund']);
        }

        return [
            'by_currency' => array_values($byCur),
            'anomalies' => self::anomalies($scope, $byCur),
            'multi_currency' => count($byCur) > 1,
        ];
    }

    /**
     * Receivables drill-down (ADR-584) — explains the net accounting receivable, per currency:
     *   gross document balance (Σ amount_due, non-void) − written-off (uncollectible) = net recoverable,
     * reconciled against the ledger AR net. Returns the contributing invoices with a written_off flag,
     * so the operator sees e.g. 103,20 € documents − 34,80 € (uncollectible #1) = 68,40 € net.
     *
     * @return array<int, array<string, mixed>>  one entry per currency
     */
    public static function receivablesBreakdown(?int $companyId = null): array
    {
        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;

        // Ledger AR net per currency (cents).
        $arByCur = [];
        foreach ($scope(LedgerEntry::query())->where('account_code', 'AR')
            ->select('currency', DB::raw('SUM(debit) d'), DB::raw('SUM(credit) c'))
            ->groupBy('currency')->get() as $row) {
            $arByCur[$row->currency] = (int) round((float) $row->d - (float) $row->c);
        }

        $invoices = $scope(Invoice::query())->where('status', '!=', 'void')
            ->get(['id', 'number', 'company_id', 'status', 'amount_due', 'currency']);

        $out = [];
        foreach ($invoices->groupBy('currency') as $cur => $rows) {
            $lines = [];
            $gross = 0;
            $writtenOff = 0;
            foreach ($rows as $inv) {
                $due = (int) $inv->amount_due;
                $isWrittenOff = $inv->status === 'uncollectible'
                    || LedgerEntry::where('reference_type', 'invoice')->where('reference_id', $inv->id)->where('entry_type', 'writeoff')->exists();
                $gross += $due;
                if ($isWrittenOff) {
                    $writtenOff += $due;
                }
                $lines[] = ['invoice_id' => $inv->id, 'number' => $inv->number, 'company_id' => $inv->company_id, 'status' => $inv->status, 'amount_due_cents' => $due, 'written_off' => $isWrittenOff];
            }
            $net = $gross - $writtenOff;
            $ledgerAr = $arByCur[$cur] ?? 0;
            $out[] = [
                'currency' => $cur,
                'gross_documents_cents' => $gross,
                'written_off_cents' => $writtenOff,
                'net_recoverable_cents' => $net,
                'ledger_ar_cents' => $ledgerAr,
                'reconciled' => $net === $ledgerAr,
                'gap_cents' => $ledgerAr - $net,
                'invoices' => $lines,
            ];
        }

        return $out;
    }

    /**
     * Revenue drill-down (ADR-584) — the invoices whose REVENUE (HT) ledger credit falls within the
     * window, each with its HT contribution; the total equals the revenue-trend KPI for that period.
     *
     * @return array<int, array<string, mixed>>  one entry per currency
     */
    public static function revenueBreakdown(\Carbon\Carbon $from, \Carbon\Carbon $to, ?int $companyId = null): array
    {
        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;

        $rows = $scope(LedgerEntry::query())
            ->where('entry_type', 'invoice_issued')->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->select('currency', 'reference_type', 'reference_id', DB::raw('SUM(credit) ht'))
            ->groupBy('currency', 'reference_type', 'reference_id')
            ->get();

        $out = [];
        foreach ($rows->groupBy('currency') as $cur => $entries) {
            $lines = [];
            $total = 0;
            foreach ($entries as $e) {
                $ht = (int) round((float) $e->ht);
                $total += $ht;
                $inv = $e->reference_type === 'invoice' ? Invoice::find($e->reference_id) : null;
                $lines[] = ['invoice_id' => $e->reference_id, 'number' => $inv?->number, 'company_id' => $inv?->company_id, 'ht_cents' => $ht, 'issued_at' => optional($inv?->issued_at)->toDateString()];
            }
            $out[] = ['currency' => $cur, 'revenue_ht_cents' => $total, 'invoices' => $lines];
        }

        return $out;
    }

    /**
     * Accounts-receivable AGING (ADR-586) — buckets outstanding invoice balances by how long past
     * their due date they are, as of an explicit reference date. Per currency, integer cents.
     * Written-off (uncollectible) balances are reported SEPARATELY and excluded from the recoverable
     * buckets — a bad debt is not a normally-recoverable receivable. Documentary vs net is explicit.
     *
     * Buckets: not_due (no due date or due in the future), d0_30, d31_60, d61_90, d90_plus.
     *
     * @return array<int, array<string, mixed>>  one entry per currency
     */
    public static function agingReport(?int $companyId = null, ?\Carbon\Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;

        $invoices = $scope(Invoice::query())
            ->where('status', '!=', 'void')->where('amount_due', '>', 0)
            ->get(['id', 'company_id', 'status', 'amount_due', 'currency', 'due_at']);

        $out = [];
        foreach ($invoices->groupBy('currency') as $cur => $rows) {
            $b = ['not_due' => 0, 'd0_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'written_off' => 0];
            foreach ($rows as $inv) {
                $due = (int) $inv->amount_due;
                $isWrittenOff = $inv->status === 'uncollectible'
                    || LedgerEntry::where('reference_type', 'invoice')->where('reference_id', $inv->id)->where('entry_type', 'writeoff')->exists();
                if ($isWrittenOff) {
                    $b['written_off'] += $due;

                    continue; // a bad debt is not a normally-recoverable receivable
                }
                if (! $inv->due_at || $inv->due_at->greaterThanOrEqualTo($asOf)) {
                    $b['not_due'] += $due;

                    continue;
                }
                $daysLate = (int) $inv->due_at->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay());
                $bucket = $daysLate <= 30 ? 'd0_30' : ($daysLate <= 60 ? 'd31_60' : ($daysLate <= 90 ? 'd61_90' : 'd90_plus'));
                $b[$bucket] += $due;
            }
            $recoverable = $b['not_due'] + $b['d0_30'] + $b['d31_60'] + $b['d61_90'] + $b['d90_plus'];
            $out[] = [
                'currency' => $cur,
                'as_of' => $asOf->toDateString(),
                'buckets' => $b,
                'overdue_cents' => $b['d0_30'] + $b['d31_60'] + $b['d61_90'] + $b['d90_plus'],
                'net_recoverable_cents' => $recoverable,
                'written_off_cents' => $b['written_off'],
                'total_documentary_cents' => $recoverable + $b['written_off'],
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function anomalies(callable $scope, array $byCur): array
    {
        $out = [];

        // A) Ledger AR ≠ sum of invoice amount_due (per currency) — the headline reconciliation gap.
        foreach ($byCur as $cur => $c) {
            if (($c['gap_ar_vs_invoices'] ?? 0) !== 0) {
                $out[] = [
                    'type' => 'ar_ledger_vs_invoices_mismatch', 'severity' => 'error', 'currency' => $cur,
                    'ledger_ar_cents' => $c['ledger_ar'], 'invoices_outstanding_cents' => $c['invoices_outstanding'],
                    'gap_cents' => $c['gap_ar_vs_invoices'],
                    'detail' => 'Le solde AR du grand livre diffère de la somme des montants dus des factures.',
                ];
            }
            if (($c['gap_cash_vs_payments'] ?? 0) !== 0) {
                $out[] = [
                    'type' => 'cash_ledger_vs_payments_mismatch', 'severity' => 'warning', 'currency' => $cur,
                    'ledger_cash_cents' => $c['ledger_cash'], 'payments_succeeded_cents' => $c['payments_succeeded'],
                    'gap_cents' => $c['gap_cash_vs_payments'],
                    'detail' => 'Le CASH du grand livre diffère des paiements réussis (net des remboursements).',
                ];
            }
        }

        // B) Document-internal invoice inconsistencies.
        $paidWithDue = $scope(Invoice::query())->where('status', 'paid')->where('amount_due', '>', 0)->get(['id', 'number', 'amount_due', 'currency']);
        foreach ($paidWithDue as $inv) {
            $out[] = ['type' => 'invoice_paid_with_balance', 'severity' => 'error', 'invoice_id' => $inv->id, 'number' => $inv->number, 'amount_due_cents' => (int) $inv->amount_due, 'currency' => $inv->currency, 'detail' => 'Facture marquée payée avec un solde restant dû > 0.'];
        }
        $openNoDue = $scope(Invoice::query())->where('status', 'open')->where('amount_due', '<=', 0)->get(['id', 'number', 'currency']);
        foreach ($openNoDue as $inv) {
            $out[] = ['type' => 'invoice_open_without_balance', 'severity' => 'warning', 'invoice_id' => $inv->id, 'number' => $inv->number, 'currency' => $inv->currency, 'detail' => 'Facture ouverte sans solde dû — devrait être payée/soldée.'];
        }

        // C) VAT posting anomaly: an invoice with tax but whose ledger posting has no VAT_PAYABLE line
        //    (revenue was booked TTC, not split) — e.g. the historical May invoice. Read-only flag.
        $taxed = $scope(Invoice::query())->where('tax_amount', '>', 0)->get(['id', 'number', 'tax_amount', 'currency']);
        foreach ($taxed as $inv) {
            $hasVat = LedgerEntry::where('reference_type', 'invoice')->where('reference_id', $inv->id)->where('account_code', 'VAT_PAYABLE')->exists();
            $issued = LedgerEntry::where('reference_type', 'invoice')->where('reference_id', $inv->id)->where('entry_type', 'invoice_issued')->exists();
            if ($issued && ! $hasVat) {
                $out[] = ['type' => 'revenue_booked_without_vat_split', 'severity' => 'warning', 'invoice_id' => $inv->id, 'number' => $inv->number, 'tax_amount_cents' => (int) $inv->tax_amount, 'currency' => $inv->currency, 'detail' => 'TVA facturée mais aucune écriture VAT_PAYABLE au grand livre (revenu comptabilisé TTC). Historique — non corrigé.'];
            }
        }

        // D) Succeeded payment not posted to the ledger (unreconciled cash).
        $payments = $scope(Payment::query())->where('status', 'succeeded')->get(['id', 'amount', 'currency']);
        foreach ($payments as $p) {
            $posted = LedgerEntry::where('entry_type', 'payment_received')->where('reference_type', 'payment')->where('reference_id', $p->id)->exists();
            if (! $posted) {
                $out[] = ['type' => 'payment_not_reconciled', 'severity' => 'error', 'payment_id' => $p->id, 'amount_cents' => (int) $p->amount, 'currency' => $p->currency, 'detail' => 'Paiement réussi sans écriture payment_received au grand livre.'];
            }
        }

        return $out;
    }
}
