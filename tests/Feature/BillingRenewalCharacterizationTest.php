<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\PricingEngine;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-617 (LOT 3 — caractérisation checkout/preview/renewal). Demonstrates whether the renewal path
 * (BillingRenewCommand, which builds invoice lines inline) and the canonical PricingEngine agree on
 * the billed amounts for the cases that actually get billed (reliable contracted price — ADR-593).
 *
 * This LOCKS the current behaviour BEFORE any unification refactor. If the engines agree, BILL-004 is
 * code duplication (maintainability) rather than a financial-calculation divergence.
 */
class BillingRenewalCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlanRegistry::sync();
        MarketRegistry::sync();
    }

    private function dueSubscription(array $overrides = []): array
    {
        $company = Company::create(['name' => 'Charac', 'slug' => 'charac-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR', 'plan_key' => 'pro']);

        $sub = Subscription::create(array_merge([
            'company_id' => $company->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subMonth()->subDays(2), 'current_period_end' => now()->subDays(2),
        ], $overrides));

        CompanyAddonSubscription::create([
            'company_id' => $company->id, 'module_key' => 'workforce_leave', 'amount_cents' => 1900,
            'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()->subMonth(), 'deactivated_at' => null,
        ]);

        return [$company, $sub];
    }

    public function test_renewal_invoice_matches_pricing_engine_breakdown(): void
    {
        [$company, $sub] = $this->dueSubscription();

        Artisan::call('billing:renew');

        $invoice = Invoice::where('company_id', $company->id)->whereNotNull('finalized_at')->first();
        $this->assertNotNull($invoice, 'renewal must create an invoice');

        $breakdown = PricingEngine::forCurrentPeriod($sub->fresh(), $company->fresh());

        // The amounts the renewal actually bills MUST equal the canonical engine's breakdown.
        $this->assertSame($breakdown->subtotal, $invoice->subtotal, 'subtotal diverges renew vs PricingEngine');
        $this->assertSame($breakdown->taxAmount, $invoice->tax_amount, 'tax diverges renew vs PricingEngine');
        $this->assertSame($breakdown->total, $invoice->amount, 'total diverges renew vs PricingEngine');
        // Plan (2900) + addon (1900) = 4800 HT, +20% = 5760 TTC.
        $this->assertSame(4800, $invoice->subtotal);
        $this->assertSame(5760, $invoice->amount);
    }

    public function test_renewal_is_idempotent_no_duplicate_invoice(): void
    {
        [$company] = $this->dueSubscription();

        Artisan::call('billing:renew');
        Artisan::call('billing:renew');
        Artisan::call('billing:renew');

        $this->assertSame(1, Invoice::where('company_id', $company->id)->whereNotNull('finalized_at')->count(),
            'repeated renewal runs must not duplicate the invoice (idempotence on period)');
    }

    public function test_provisional_price_is_not_billed(): void
    {
        // ADR-593 guard: an unreliable (provisional) price must be DEFERRED, never billed.
        [$company] = $this->dueSubscription(['plan_price_source' => 'provisional']);

        Artisan::call('billing:renew');

        $this->assertSame(0, Invoice::where('company_id', $company->id)->count(),
            'provisional price must not produce a renewal invoice (deferred to review)');
    }

    /** ADR-617b: previously-uncovered case — YEARLY renewal. */
    public function test_yearly_renewal_matches_pricing_engine(): void
    {
        $company = Company::create(['name' => 'Yr', 'slug' => 'yr-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR', 'plan_key' => 'pro']);
        $sub = Subscription::create([
            'company_id' => $company->id, 'plan_key' => 'pro', 'interval' => 'yearly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 29000, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subYear()->subDays(2), 'current_period_end' => now()->subDays(2),
        ]);
        CompanyAddonSubscription::create(['company_id' => $company->id, 'module_key' => 'workforce_leave', 'amount_cents' => 1900, 'currency' => 'EUR', 'interval' => 'yearly', 'activated_at' => now()->subYear(), 'deactivated_at' => null]);

        Artisan::call('billing:renew');
        $invoice = Invoice::where('company_id', $company->id)->whereNotNull('finalized_at')->first();
        $b = PricingEngine::forCurrentPeriod($sub->fresh(), $company->fresh());

        $this->assertNotNull($invoice);
        $this->assertSame($b->subtotal, $invoice->subtotal);
        $this->assertSame($b->total, $invoice->amount);
    }

    /** ADR-617b: previously-uncovered case — MULTIPLE addons combined. */
    public function test_multi_addon_renewal_matches_pricing_engine(): void
    {
        [$company, $sub] = $this->dueSubscription();
        CompanyAddonSubscription::create(['company_id' => $company->id, 'module_key' => 'workforce_planning', 'amount_cents' => 2900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()->subMonth(), 'deactivated_at' => null]);
        CompanyAddonSubscription::create(['company_id' => $company->id, 'module_key' => 'workforce_documents', 'amount_cents' => 900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()->subMonth(), 'deactivated_at' => null]);

        Artisan::call('billing:renew');
        $invoice = Invoice::where('company_id', $company->id)->whereNotNull('finalized_at')->first();
        $b = PricingEngine::forCurrentPeriod($sub->fresh(), $company->fresh());

        $this->assertSame($b->subtotal, $invoice->subtotal, 'multi-addon subtotal diverges');
        $this->assertSame($b->total, $invoice->amount, 'multi-addon total diverges');
        // Plan 2900 + leave 1900 + planning 2900 + documents 900 = 8600 HT.
        $this->assertSame(8600, $invoice->subtotal);
    }

    /** ADR-617b: previously-uncovered case — a COUPON attached (same discount logic on both paths). */
    public function test_coupon_renewal_matches_pricing_engine(): void
    {
        $coupon = \App\Core\Billing\BillingCoupon::create([
            'code' => 'RECETTE10', 'name' => '10%', 'type' => 'percentage', 'value' => 10,
            'is_active' => true, 'used_count' => 0, 'duration_months' => null,
        ]);
        [$company, $sub] = $this->dueSubscription(['coupon_id' => $coupon->id, 'coupon_months_remaining' => null]);

        Artisan::call('billing:renew');
        $invoice = Invoice::where('company_id', $company->id)->whereNotNull('finalized_at')->first();
        $b = PricingEngine::forCurrentPeriod($sub->fresh(), $company->fresh());

        // Both paths must apply the SAME coupon discount (whatever it computes to).
        $this->assertSame($b->subtotal, $invoice->subtotal, 'coupon subtotal diverges renew vs PricingEngine');
        $this->assertSame($b->total, $invoice->amount, 'coupon total diverges renew vs PricingEngine');
    }
}
