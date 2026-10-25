<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BILL-010 (LOT 7B) — the Prometheus billing export counts the CANONICAL 'void' status. A regression
 * had it iterate 'voided', so the void bucket always exported 0 and voided invoices disappeared from
 * monitoring. This pins the correct literal.
 */
class BillingMetricsExportVoidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        config(['billing.metrics.token' => 'test-token-123']);
    }

    public function test_voided_invoice_is_counted_under_the_void_bucket(): void
    {
        $company = Company::create(['name' => 'Exp Co', 'slug' => 'exp-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
        Invoice::create([
            'company_id' => $company->id, 'number' => 'INV-VOID-1', 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 0, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'void', 'issued_at' => now(), 'finalized_at' => now(), 'voided_at' => now(),
        ]);

        $body = $this->withToken('test-token-123')
            ->get('/api/platform/billing/metrics-export')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('billing_invoices_total{status="void"} 1', $body);
        // The stale literal must not re-appear as a populated bucket.
        $this->assertStringNotContainsString('billing_invoices_total{status="voided"} 1', $body);
    }
}
