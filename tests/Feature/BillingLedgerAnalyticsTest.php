<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillingInvoiceLineAnalytic;
use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Billing\TaxAllocation;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-632 (M7) — ventilation analytique d'une facture consolidée V2, réconciliée AU CENTIME avec les
 * totaux comptables (le grand livre reste au niveau facture). Allocation déterministe de la TVA.
 */
class BillingLedgerAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private BillingRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        JobdomainRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
        $this->run = new BillingRun;
        config(['billing.consolidated.emit' => true]);
        Notification::fake();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'An Co', 'slug' => 'an-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => '2026-11-01 00:00:00', 'current_period_end' => '2026-11-30 23:59:59',
            'v2_effective_at' => '2026-10-01 00:00:00', 'commercial_policy_version' => 'v2.0',
        ]);
    }

    private function addon(Company $c, string $key, int $cents): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR',
            'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null,
        ]);
    }

    public function test_tax_allocation_sums_to_aggregate_largest_remainder(): void
    {
        // 3 lignes HT 1000/1000/1000, TVA agrégée cible 601 (ex. non divisible) → Σ == 601, déterministe.
        $alloc = TaxAllocation::allocate([10 => 1000, 11 => 1000, 12 => 1000], 601);
        $this->assertSame(601, array_sum($alloc));
        $this->assertEqualsCanonicalizing([201, 200, 200], array_values($alloc));
    }

    public function test_tax_allocation_ignores_zero_aggregate(): void
    {
        $this->assertSame([5 => 0, 6 => 0], TaxAllocation::allocate([5 => 1000, 6 => -500], 0));
    }

    public function test_analytics_reconciles_to_invoice_totals(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $this->addon($c, 'workforce_planning', 2900);
        BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => 'workforce_docs', 'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00',
            'amount_ht_cents' => 1496, 'status' => 'eligible', 'idempotency_key' => 'ev-an',
        ]);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);

        $analytics = BillingInvoiceLineAnalytic::where('invoice_id', $inv->id)->get();
        $this->assertSame(3, $analytics->count(), '1 ventilation par ligne');
        // Réconciliation AU CENTIME.
        $this->assertSame((int) $inv->subtotal, $analytics->sum('ht_cents'), 'Σ HT = subtotal');
        $this->assertSame((int) $inv->tax_amount, $analytics->sum('tax_cents'), 'Σ TVA = TVA agrégée (fait foi)');
        $this->assertSame((int) $inv->subtotal + (int) $inv->tax_amount, $analytics->sum('ttc_cents'), 'Σ TTC = total');
        // Lien event ↔ ventilation du prorata.
        $prorata = $analytics->firstWhere('billable_event_id', '!=', null);
        $this->assertNotNull($prorata);
        $this->assertSame('workforce_docs', $prorata->module_key);
    }

    public function test_analytics_134_modules_reconciles(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        for ($i = 1; $i <= 134; $i++) {
            $this->addon($c, "mod_{$i}", 100);
        }
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        $analytics = BillingInvoiceLineAnalytic::where('invoice_id', $inv->id)->get();

        $this->assertSame(135, $analytics->count());
        $this->assertSame((int) $inv->subtotal, $analytics->sum('ht_cents'));
        $this->assertSame((int) $inv->tax_amount, $analytics->sum('tax_cents'), 'Σ TVA ligne = agrégat même sur 135 lignes');
    }

    public function test_analytics_is_idempotent(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        $before = BillingInvoiceLineAnalytic::where('invoice_id', $inv->id)->count();
        \App\Core\Billing\LedgerService::recordInvoiceAnalytics($inv->fresh()); // rejeu
        $this->assertSame($before, BillingInvoiceLineAnalytic::where('invoice_id', $inv->id)->count());
    }

    public function test_v1_renewal_has_no_analytics(): void
    {
        // Une facture V1 (créée hors BillingRun) n'a PAS de ventilation analytique (M7 = V2 seulement).
        $c = $this->company();
        $inv = Invoice::create([
            'company_id' => $c->id, 'billing_model_version' => 1, 'number' => 'INV-V1', 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);
        $this->assertSame(0, BillingInvoiceLineAnalytic::where('invoice_id', $inv->id)->count());
    }
}
