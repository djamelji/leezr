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
