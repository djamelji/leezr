<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Modules\Platform\Plans\UseCases\ConfirmContractedPriceUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-593 — snapshot provenance & safe renewal. The current catalog price is never proof of the
 * contracted price: a provisional/missing snapshot defers the renewal into tariff review (no invoice).
 * A reliable provenance (subscribed / plan_change / migrated / reconstructed / admin_confirmed) bills
 * normally. Administrative confirmation turns a provisional snapshot reliable (audited).
 */
class SubscriptionSnapshotSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        Plan::where('key', 'pro')->update(['price_monthly' => 3000, 'price_yearly' => 30000]);
        $this->companyId = Company::create(['name' => 'Snap Co', 'slug' => 'snap-co', 'market_key' => 'FR', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique'])->id;
    }

    private function rawSubscription(?string $source, ?int $cents): int
    {
        return DB::table('subscriptions')->insertGetId([
            'company_id' => $this->companyId, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => 1, 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(2),
            'plan_price_cents' => $cents, 'plan_price_source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_new_subscription_snapshots_reliably_on_create(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->companyId, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        $this->assertSame(3000, (int) $sub->plan_price_cents);
        $this->assertSame('subscribed', $sub->plan_price_source);
        $this->assertTrue($sub->hasReliableContractedPrice());
    }

    public function test_provisional_snapshot_defers_renewal_into_tariff_review_without_invoice(): void
    {
        // Provisional = catalog backfill (unverified). Renewal must NOT bill it.
        $id = $this->rawSubscription('provisional', 2900);
        $invoicesBefore = Invoice::withoutGlobalScopes()->count();

        Artisan::call('billing:renew');

        $this->assertSame($invoicesBefore, Invoice::withoutGlobalScopes()->count(), 'no invoice issued for an unverified price');
        $meta = Subscription::find($id)->metadata ?? [];
        $this->assertTrue($meta['pricing_review_required'] ?? false, 'classified for tariff review');
        $this->assertSame('unverified_snapshot', $meta['pricing_review_reason']);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.renewal_deferred_pricing_review', 'target_id' => (string) $id]);
    }

    public function test_missing_snapshot_defers_renewal_without_inventing_a_price(): void
    {
        $id = $this->rawSubscription(null, null);
        $invoicesBefore = Invoice::withoutGlobalScopes()->count();

        Artisan::call('billing:renew');

        $this->assertSame($invoicesBefore, Invoice::withoutGlobalScopes()->count(), 'no invoice — no price invented');
        $this->assertNull(Subscription::find($id)->plan_price_cents, 'catalog not captured as contract');
        $this->assertSame('missing_snapshot', (Subscription::find($id)->metadata ?? [])['pricing_review_reason'] ?? null);
    }

    public function test_reliable_snapshot_renews_normally(): void
    {
        $id = $this->rawSubscription('subscribed', 3000);

        Artisan::call('billing:renew');

        $inv = Invoice::withoutGlobalScopes()->where('company_id', $this->companyId)->latest('id')->first();
        $this->assertNotNull($inv, 'reliable price → renewal proceeds');
        $this->assertSame(3000, (int) $inv->subtotal);
    }

    public function test_admin_confirmation_makes_a_provisional_snapshot_reliable_and_renewable(): void
    {
        $id = $this->rawSubscription('provisional', 2900);

        // Admin confirms the contracted price (here: 2500, with proof) — audited.
        $sub = (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 1, 'Signed order form #42');
        $this->assertSame('admin_confirmed', $sub->plan_price_source);
        $this->assertSame(2500, (int) $sub->plan_price_cents);
        $this->assertTrue($sub->hasReliableContractedPrice());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.contracted_price_confirmed', 'target_id' => (string) $id]);

        // Now renewal proceeds and bills the confirmed price.
        Artisan::call('billing:renew');
        $inv = Invoice::withoutGlobalScopes()->where('company_id', $this->companyId)->latest('id')->first();
        $this->assertSame(2500, (int) $inv->subtotal, 'renewal bills the administratively confirmed price');
    }
}
