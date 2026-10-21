<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Price Versioning (ADR-587) — subscription contracted-price protection.
 * A public price change must NEVER change the amount billed to an already-subscribed customer:
 * renewal honours a per-subscription price snapshot. New subscriptions use the current published
 * price; an explicit plan change / tariff migration re-snapshots; issued invoices stay immutable;
 * addons already honour their own snapshot.
 */
class SubscriptionPriceProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        Plan::where('key', 'pro')->update(['price_monthly' => 3000, 'price_yearly' => 30000]);
        $this->company = Company::create(['name' => 'PriceCo', 'slug' => 'price-co', 'market_key' => 'FR', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
    }

    private function company(string $slug): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug, 'market_key' => 'FR', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
    }

    private function arm(Company $c): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(2),
        ]);
    }

    private function renew(): void
    {
        \Illuminate\Support\Facades\Artisan::call('billing:renew');
    }

    private function latestInvoiceSubtotal(Company $c): ?int
    {
        $inv = Invoice::withoutGlobalScopes()->where('company_id', $c->id)->latest('id')->first();

        return $inv ? (int) $inv->subtotal : null;
    }

    public function test_subscription_snapshots_the_contracted_price_at_creation(): void
    {
        $sub = $this->arm($this->company);
        $this->assertSame(3000, (int) $sub->plan_price_cents, 'the contracted price (30,00 €) is snapshotted at subscription');
    }

    public function test_existing_subscriber_keeps_its_contracted_price_when_public_price_changes(): void
    {
        $sub = $this->arm($this->company);
        $this->renew();
        $this->assertSame(3000, $this->latestInvoiceSubtotal($this->company), 'first renewal at 30,00 €');
        $inv1 = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->latest('id')->first();

        // Platform drops the PUBLIC price to 1,00 €.
        Plan::where('key', 'pro')->update(['price_monthly' => 100]);

        // Re-arm for a distinct due period and renew again.
        $sub->update(['current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(5)]);
        $this->renew();
        $inv2 = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('id', '!=', $inv1->id)->latest('id')->first();

        $this->assertNotNull($inv2);
        $this->assertSame(3000, (int) $inv2->subtotal, 'PROTECTED: renewal keeps the contracted 30,00 €, not the new 1,00 €');
        $this->assertSame(3000, (int) $inv1->fresh()->subtotal, 'already-issued invoice stays immutable');
    }

    public function test_new_subscription_uses_the_current_published_price(): void
    {
        Plan::where('key', 'pro')->update(['price_monthly' => 100]); // public price is now 1,00 €
        $b = $this->company('co-b');
        $subB = $this->arm($b);
        $this->assertSame(100, (int) $subB->plan_price_cents, 'new subscriber contracted at the current 1,00 €');
        $this->renew();
        $this->assertSame(100, $this->latestInvoiceSubtotal($b), 'new subscriber billed 1,00 €');
    }

    public function test_explicit_tariff_migration_moves_a_subscriber_to_the_new_price(): void
    {
        $sub = $this->arm($this->company);
        // Explicit, authorized contractual change: re-snapshot to the new price.
        $sub->update(['plan_price_cents' => 100, 'current_period_end' => now()->subDays(2)]);
        $this->renew();
        $this->assertSame(100, $this->latestInvoiceSubtotal($this->company), 'after explicit migration, renewal bills 1,00 €');
    }

    public function test_addon_renewal_honours_the_contracted_snapshot(): void
    {
        PlatformModule::updateOrCreate(['key' => 'workforce'], ['name' => 'Workforce', 'is_enabled_globally' => true, 'addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price' => ['pro' => 500]]]]);
        CompanyAddonSubscription::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'name' => 'Workforce', 'amount_cents' => 500, 'interval' => 'monthly', 'status' => 'active', 'activated_at' => now()]);

        $this->arm($this->company);
        PlatformModule::where('key', 'workforce')->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price' => ['pro' => 800]]]]);

        $this->renew();
        $addonLine = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->latest('id')->first()->lines()->where('type', 'addon')->first();
        $this->assertSame(500, (int) $addonLine->amount, 'addon renewal honours the contracted snapshot (500), not the new catalog price (800)');
    }
}
