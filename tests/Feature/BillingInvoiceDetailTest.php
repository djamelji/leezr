<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-622 (LOT 6e — facture & PDF). Invoice detail invariants: HT + tax = TTC, amount_due
 * reconciliation, and HISTORICAL tax preservation — an issued invoice never recomputes its tax from
 * the current market rate.
 */
class BillingInvoiceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
    }

    private function finalizedInvoice(Company $c, int $subtotal, int $taxBps): Invoice
    {
        $tax = (int) floor($subtotal * $taxBps / 10000);
        $inv = Invoice::create([
            'company_id' => $c->id, 'number' => 'INV-'.uniqid(), 'subtotal' => $subtotal, 'tax_amount' => $tax,
            'tax_rate_bps' => $taxBps, 'amount' => $subtotal + $tax, 'amount_due' => $subtotal + $tax,
            'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open',
            'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
            'billing_snapshot' => ['market_locale' => 'fr-FR', 'tax_rate_bps' => $taxBps],
        ]);
        InvoiceLine::create(['invoice_id' => $inv->id, 'type' => 'plan', 'description' => 'Pro', 'quantity' => 1, 'unit_amount' => $subtotal, 'amount' => $subtotal]);

        return $inv;
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Inv Co', 'slug' => 'inv-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    public function test_ht_plus_tax_equals_ttc_and_due_reconciles(): void
    {
        $c = $this->company();
        $inv = $this->finalizedInvoice($c, 2900, 2000); // 29.00 HT, 20%

        $d = CompanyBillingReadService::invoiceDetail($c, $inv->id);

        $this->assertSame(2900, $d['subtotal']);
        $this->assertSame(580, $d['tax_amount']);
        $this->assertSame(3480, $d['amount'], 'TTC = HT + tax');
        $this->assertSame($d['subtotal'] + $d['tax_amount'], $d['amount'], 'reconciliation HT+tax=TTC');
        $this->assertSame(3480, $d['amount_due'], 'nothing paid → full TTC due');
    }

    public function test_issued_invoice_keeps_historical_tax_after_market_rate_change(): void
    {
        $c = $this->company();
        $inv = $this->finalizedInvoice($c, 2900, 2000); // issued at 20%

        // The market VAT rate later changes to 10% — the ISSUED invoice must NOT change.
        Market::where('key', 'FR')->update(['vat_rate_bps' => 1000]);

        $d = CompanyBillingReadService::invoiceDetail($c->fresh(), $inv->id);

        $this->assertSame(2000, $d['tax_rate_bps'], 'issued invoice keeps its historical tax rate');
        $this->assertSame(580, $d['tax_amount'], 'historical tax amount preserved (not recomputed at 10%)');
        $this->assertSame(3480, $d['amount']);
    }

    public function test_invoice_not_owned_by_company_is_not_accessible(): void
    {
        $c1 = $this->company();
        $c2 = $this->company();
        $inv = $this->finalizedInvoice($c1, 2900, 2000);

        // Multi-tenant isolation: company 2 cannot read company 1's invoice.
        $this->assertNull(CompanyBillingReadService::invoiceDetail($c2, $inv->id));
    }
}
