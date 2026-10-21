<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\ReadModels\FinanceReconciliationService;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Billing Cockpit 2.0 — PRIORITY 1 reconciliation (read-only). Proves the ledger↔documents
 * reconciliation and that divergences surface as explicit anomalies (never masked), per currency,
 * in integer cents.
 */
class FinanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        Company::create(['name' => 'Co1', 'slug' => 'co1', 'market_key' => 'FR']);
    }

    private function invoice(array $a): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'company_id' => 1, 'number' => 'INV-'.uniqid(), 'amount' => 0, 'subtotal' => 0, 'tax_amount' => 0,
            'amount_due' => 0, 'currency' => 'EUR', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    private function ledger(array $a): void
    {
        LedgerEntry::create(array_merge([
            'company_id' => 1, 'currency' => 'EUR', 'debit' => 0, 'credit' => 0,
            'reference_type' => 'invoice', 'reference_id' => 1,
            'correlation_id' => 'rec-'.uniqid(), 'recorded_at' => now(),
        ], $a));
    }

    public function test_reconciled_scenario_has_no_ar_gap_or_anomalies(): void
    {
        // Invoice TTC 2280 (HT 1900 + VAT 380), open, amount_due 2280.
        $inv = $this->invoice(['amount' => 2280, 'subtotal' => 1900, 'tax_amount' => 380, 'amount_due' => 2280, 'status' => 'open']);
        $this->ledger(['reference_id' => $inv, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 2280]);
        $this->ledger(['reference_id' => $inv, 'entry_type' => 'invoice_issued', 'account_code' => 'REVENUE', 'credit' => 1900]);
        $this->ledger(['reference_id' => $inv, 'entry_type' => 'invoice_issued', 'account_code' => 'VAT_PAYABLE', 'credit' => 380]);

        $r = FinanceReconciliationService::report();
        $eur = collect($r['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame(2280, $eur['ledger_ar']);
        $this->assertSame(2280, $eur['invoices_outstanding']);
        $this->assertSame(0, $eur['gap_ar_vs_invoices'], 'ledger AR reconciles to invoice amount_due');
        $this->assertFalse($r['multi_currency']);
        $this->assertCount(0, $r['anomalies']);
    }

    public function test_divergences_surface_as_anomalies(): void
    {
        // (1) AR gap: invoice amount_due 1000 but no AR ledger debit → ledger_ar(0) ≠ outstanding(1000).
        $this->invoice(['amount' => 1000, 'subtotal' => 1000, 'amount_due' => 1000, 'status' => 'open']);

        // (2) VAT anomaly: taxed invoice issued to the ledger WITHOUT a VAT_PAYABLE line (booked TTC).
        $inv2 = $this->invoice(['amount' => 3480, 'subtotal' => 2900, 'tax_amount' => 580, 'amount_due' => 0, 'status' => 'paid']);
        $this->ledger(['reference_id' => $inv2, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 3480]);
        $this->ledger(['reference_id' => $inv2, 'entry_type' => 'invoice_issued', 'account_code' => 'REVENUE', 'credit' => 3480]);

        // (3) Unreconciled payment: a succeeded payment with no ledger payment_received entry.
        DB::table('payments')->insert(['company_id' => 1, 'amount' => 3480, 'currency' => 'EUR', 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);

        $r = FinanceReconciliationService::report();
        $types = collect($r['anomalies'])->pluck('type')->all();

        $this->assertContains('ar_ledger_vs_invoices_mismatch', $types);
        $this->assertContains('revenue_booked_without_vat_split', $types);
        $this->assertContains('payment_not_reconciled', $types);

        // The KPI is NOT silently adjusted: the gap is reported with its exact cents.
        $arAnomaly = collect($r['anomalies'])->firstWhere('type', 'ar_ledger_vs_invoices_mismatch');
        // ledger_ar = 3480 (inv2 AR debit); invoices_outstanding = 1000 (inv1 open) + 0 (inv2 paid) = 1000.
        $this->assertSame(2480, $arAnomaly['gap_cents']);
    }
}
