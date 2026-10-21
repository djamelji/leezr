<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\PlanPricingGovernanceService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Modules\Platform\Plans\UseCases\MigrateSubscriptionPriceUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pricing governance (ADR-588): impact of a public price change (real numbers), explicit tariff
 * migration (preview has no financial effect; apply is idempotent + audited; scheduled migration
 * is honoured at renewal). Changing a public price never migrates existing subscribers.
 */
class PlanPricingGovernanceTest extends TestCase
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

    public function test_impact_reports_real_affected_subscriptions_per_currency(): void
    {
        $this->subscribe('co-1');
        $this->subscribe('co-2');

        $impact = PlanPricingGovernanceService::impact('pro', 'monthly', 3900);

        $this->assertSame(3000, $impact['current_public_cents']);
        $this->assertSame(3900, $impact['proposed_cents']);
        $this->assertSame(2, $impact['affected_subscriptions']);
        $eur = collect($impact['by_currency'])->firstWhere('currency', 'EUR');
        $this->assertSame(2, $eur['count'], '2 existing subscribers keep their contracted price');
        $this->assertSame(3000, $eur['max_contracted_cents']);
    }

    public function test_subscriptions_for_plan_lists_selectable_rows_with_contracted_and_public_price(): void
    {
        $s = $this->subscribe('co-list');
        $rows = PlanPricingGovernanceService::subscriptionsForPlan('pro', 'monthly');

        $this->assertCount(1, $rows);
        $this->assertSame($s->id, $rows[0]['subscription_id']);
        $this->assertSame(3000, $rows[0]['contracted_cents']);
        $this->assertSame(3000, $rows[0]['public_cents']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertNull($rows[0]['scheduled_migration']);
    }

    public function test_changing_public_price_does_not_migrate_existing_subscribers(): void
    {
        $sub = $this->subscribe('co-keep');
        Plan::where('key', 'pro')->update(['price_monthly' => 3900]); // public price up
        $this->assertSame(3000, (int) $sub->fresh()->plan_price_cents, 'existing subscriber keeps 3000, not migrated');
    }

    public function test_migration_preview_has_no_financial_effect(): void
    {
        $sub = $this->subscribe('co-prev');
        $invoicesBefore = Invoice::withoutGlobalScopes()->count();

        $preview = (new MigrateSubscriptionPriceUseCase())->preview([$sub->id], 3900);

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count() - $invoicesBefore, 'preview issues no invoice');
        $this->assertSame(3000, (int) $sub->fresh()->plan_price_cents, 'preview does not change the price');
        $line = $preview['lines'][0];
        $this->assertSame(900, $line['delta_cents'], 'delta 3900 − 3000');
    }

    public function test_migration_apply_immediate_is_idempotent_and_audited(): void
    {
        $sub = $this->subscribe('co-mig');
        $uc = new MigrateSubscriptionPriceUseCase();

        $s1 = $uc->apply([$sub->id], 3900, 'immediate', null, 1);
        $this->assertSame(1, $s1['migrated']);
        $this->assertSame(3900, (int) $sub->fresh()->plan_price_cents);

        // Idempotent: applying the same target again changes nothing.
        $s2 = $uc->apply([$sub->id], 3900, 'immediate', null, 1);
        $this->assertSame(0, $s2['migrated']);
        $this->assertSame(1, $s2['skipped']);

        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.price_migrated', 'target_id' => (string) $sub->id]);
    }

    public function test_scheduled_migration_is_applied_at_next_renewal(): void
    {
        $sub = $this->subscribe('co-sched');
        (new MigrateSubscriptionPriceUseCase())->apply([$sub->id], 100, 'at_renewal', now()->subDay(), 1);
        $this->assertArrayHasKey('scheduled_price_migration', $sub->fresh()->metadata);

        \Illuminate\Support\Facades\Artisan::call('billing:renew');

        $fresh = $sub->fresh();
        $this->assertSame(100, (int) $fresh->plan_price_cents, 'scheduled migration applied at renewal');
        $this->assertArrayNotHasKey('scheduled_price_migration', $fresh->metadata ?? [], 'schedule cleared');
        $inv = Invoice::withoutGlobalScopes()->where('company_id', $sub->company_id)->latest('id')->first();
        $this->assertSame(100, (int) $inv->subtotal, 'renewal billed the migrated price');
    }

    public function test_public_price_change_is_audited(): void
    {
        $plan = Plan::where('key', 'pro')->first();
        app(\App\Modules\Platform\Plans\UseCases\UpsertPlanUseCase::class)->execute($plan->id, [
            'key' => 'pro', 'name' => 'Pro', 'level' => 2, 'price_monthly' => 39, 'price_yearly' => 390, 'is_active' => true,
        ]);

        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'plan.public_price_changed', 'target_id' => (string) $plan->id]);
    }
}
