<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-592 — snapshot safety. A MISSING contracted snapshot must never lead to a silent/incorrect
 * billing: at renewal the catalog price is captured explicitly (audited); seeded subscriptions are
 * guaranteed a usable contracted price.
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

    /** Insert a subscription bypassing the model (so the creating hook does NOT snapshot). */
    private function rawSubscription(): int
    {
        return DB::table('subscriptions')->insertGetId([
            'company_id' => $this->companyId, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => 1, 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(2),
            'plan_price_cents' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_missing_snapshot_is_captured_explicitly_and_audited_at_renewal(): void
    {
        $id = $this->rawSubscription();
        $this->assertNull(Subscription::find($id)->plan_price_cents, 'starts without a snapshot');

        Artisan::call('billing:renew');

        $sub = Subscription::find($id);
        $this->assertSame(3000, (int) $sub->plan_price_cents, 'catalog price captured as the contracted snapshot');
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.snapshot_captured_at_renewal', 'target_id' => (string) $id]);
        $inv = Invoice::withoutGlobalScopes()->where('company_id', $this->companyId)->latest('id')->first();
        $this->assertSame(3000, (int) $inv->subtotal, 'billed at the captured contracted price (not a blind price)');
    }

    public function test_seeding_backfill_guarantees_a_usable_contracted_price(): void
    {
        $id = $this->rawSubscription(); // null snapshot, as a pre-existing seeded row would be

        // The exact idempotent backfill DevSeeder runs (ADR-592).
        Subscription::whereNull('plan_price_cents')->get()->each(function (Subscription $s) {
            $s->update(['plan_price_cents' => $s->contractedPlanPriceCents()]);
        });

        $this->assertSame(3000, (int) Subscription::find($id)->plan_price_cents, 'seeded subscription has an exploitable contracted price');
        $this->assertSame(0, Subscription::whereNull('plan_price_cents')->count(), 'no active subscription left without a snapshot');
    }

    public function test_the_contracted_model_mechanism_snapshots_on_create(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->companyId, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        $this->assertSame(3000, (int) $sub->plan_price_cents, 'the creating hook snapshots the contracted price');
    }
}
