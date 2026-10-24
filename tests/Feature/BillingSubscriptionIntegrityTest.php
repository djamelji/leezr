<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Markets\MarketRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-615 (LOT 1 — intégrité des abonnements). Validates the FOUR business dimensions on the
 * Leezr-Logistics-like scenario (active contract, period ended in the past, renewal in review,
 * provisional price), and that they render independently & correctly — no past date shown as a
 * future renewal, no retroactive invoicing, payment state independent of renewal state.
 */
class BillingSubscriptionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();

        $this->company = Company::create([
            'name' => 'Leezr-like', 'slug' => 'leezr-like-'.uniqid(),
            'market_key' => 'FR', 'address_country_code' => 'FR', 'jobdomain_key' => 'logistique',
        ]);

        // Active Pro, period ended 5 months ago, provisional (unreliable) price, internal provider.
        Subscription::create([
            'company_id' => $this->company->id, 'plan_key' => 'pro', 'interval' => 'monthly',
            'status' => 'active', 'provider' => 'internal', 'is_current' => true,
            'plan_price_cents' => 2900, 'plan_price_source' => 'provisional',
            'current_period_start' => now()->subMonths(5)->startOfMonth(),
            'current_period_end' => now()->subMonths(5)->endOfMonth(),
            'metadata' => ['pricing_review_required' => true, 'renewal_review_required' => true],
        ]);

        // One addon invoice, open, due in the FUTURE (not an overdue / payment problem).
        $inv = Invoice::create([
            'company_id' => $this->company->id, 'number' => 'INV-'.uniqid(), 'subtotal' => 1900,
            'tax_amount' => 380, 'tax_rate_bps' => 2000, 'amount' => 2280, 'amount_due' => 2280,
            'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open',
            'issued_at' => now()->subDays(3), 'finalized_at' => now()->subDays(3), 'due_at' => now()->addMonth(),
        ]);
        InvoiceLine::create(['invoice_id' => $inv->id, 'type' => 'addon', 'module_key' => 'workforce_leave', 'description' => 'Addon', 'quantity' => 1, 'unit_amount' => 1900, 'amount' => 1900]);
    }

    public function test_contractual_state_is_active(): void
    {
        $sub = CompanyBillingReadService::overview($this->company)['subscription'];
        $this->assertSame('active', $sub['status']);
    }

    public function test_coverage_period_is_in_the_past_and_not_shown_as_future_renewal(): void
    {
        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);
        // The engine must flag the overdue coverage rather than present a past date as a future bill.
        $this->assertTrue($preview['renewal_overdue'], 'period ended in the past → renewal_overdue');
        $this->assertSame(now()->subMonths(5)->endOfMonth()->toDateString(), Subscription::where('company_id', $this->company->id)->value('current_period_end')->toDateString());
    }

    public function test_renewal_is_in_review(): void
    {
        $sub = CompanyBillingReadService::overview($this->company)['subscription'];
        $this->assertTrue($sub['renewal_review_required'], 'provisional price → renewal under review');
    }

    public function test_payment_state_is_independent_of_renewal_review(): void
    {
        $ov = CompanyBillingReadService::overview($this->company);
        // Renewal is in review, yet nothing is overdue and no payment failed → NO payment issue.
        $this->assertFalse($ov['has_payment_issue'], 'renewal review must not be presented as a payment problem');
        $this->assertSame(0, $ov['invoices_overdue']);
        $this->assertSame(1, $ov['invoices_not_due']);
    }

    public function test_no_retroactive_invoicing_occurred(): void
    {
        // The 5 months of elapsed periods must NOT have produced catch-up invoices (ADR-575).
        $this->assertSame(1, Invoice::where('company_id', $this->company->id)->count(), 'no retroactive catch-up invoices');
    }
}
