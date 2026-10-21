<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-589 — the contracted price is reconstructed from the FIRST issued invoice (the amount actually
 * billed), NOT the current catalog price. Subscriptions with no billing history are flagged ambiguous
 * and left untouched (no invented price). Dry-run by default.
 */
class ReconstructContractedPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        Plan::where('key', 'pro')->update(['price_monthly' => 3000, 'price_yearly' => 30000]);
    }

    private function subscribe(string $slug): Subscription
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'market_key' => 'FR', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);

        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(2),
        ]);
    }

    public function test_reconstructs_contracted_price_from_first_invoice_not_catalog(): void
    {
        // Sub A was billed at 3000 historically.
        $a = $this->subscribe('co-a');
        Artisan::call('billing:renew'); // issues an invoice with a plan line at the contracted 3000
        $this->assertSame(3000, (int) Invoice::withoutGlobalScopes()->where('company_id', $a->company_id)->latest('id')->first()->subtotal);

        // Simulate the ADR-587 backfill having locked the WRONG (current catalog) price after a change.
        Plan::where('key', 'pro')->update(['price_monthly' => 5000]);
        $a->update(['plan_price_cents' => 5000]);

        // Dry-run changes nothing.
        Artisan::call('billing:reconstruct-contracted-price');
        $this->assertSame(5000, (int) $a->fresh()->plan_price_cents, 'dry-run does not write');

        // Apply reconstructs from the first invoice (3000), not the catalog (5000).
        Artisan::call('billing:reconstruct-contracted-price --apply');
        $this->assertSame(3000, (int) $a->fresh()->plan_price_cents, 'contracted price reconstructed from billing history');
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.contracted_price_reconstructed', 'target_id' => (string) $a->id]);
    }

    public function test_subscription_without_history_is_flagged_ambiguous_and_untouched(): void
    {
        $b = $this->subscribe('co-b'); // never billed (no invoice)
        $b->update(['plan_price_cents' => 5000]);

        Artisan::call('billing:reconstruct-contracted-price --apply');

        $this->assertSame(5000, (int) $b->fresh()->plan_price_cents, 'no invoice history → not invented, left untouched');
        $this->assertStringContainsString('ambiguous_no_history=1', Artisan::output());
    }
}
