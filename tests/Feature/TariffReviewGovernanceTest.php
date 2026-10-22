<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\PlanPricingGovernanceService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Modules\Platform\Plans\UseCases\ConfirmContractedPriceUseCase;
use App\Modules\Platform\Plans\UseCases\StaleConfirmationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-594 — tariff review governance: detect unverified subscriptions, confirm the contracted price
 * with proof (audited, concurrency-safe, idempotent, no invoice), then renew normally at that price.
 */
class TariffReviewGovernanceTest extends TestCase
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

    private function sub(string $slug, ?string $source, ?int $cents): int
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'market_key' => 'FR', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);

        return DB::table('subscriptions')->insertGetId([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'is_current' => 1,
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(2),
            'plan_price_cents' => $cents, 'plan_price_source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_review_list_includes_provisional_and_excludes_reliable(): void
    {
        $prov = $this->sub('co-prov', 'provisional', 2900);
        $this->sub('co-reliable', 'subscribed', 3000);

        $ids = collect(PlanPricingGovernanceService::reviewSubscriptions())->pluck('subscription_id');
        $this->assertTrue($ids->contains($prov), 'provisional subscription is listed for review');
        $this->assertSame(1, $ids->count(), 'reliable subscription is NOT listed');
    }

    public function test_confirmation_with_proof_makes_it_reliable_audited_and_creates_no_invoice(): void
    {
        $id = $this->sub('co-c', 'provisional', 2900);
        $before = Invoice::withoutGlobalScopes()->count();

        $sub = (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 1, 'Order form #42');

        $this->assertSame('admin_confirmed', $sub->plan_price_source);
        $this->assertSame(2500, (int) $sub->plan_price_cents);
        $this->assertSame($before, Invoice::withoutGlobalScopes()->count(), 'confirmation creates no invoice');
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.contracted_price_confirmed', 'target_id' => (string) $id]);
    }

    public function test_concurrency_conflict_is_refused(): void
    {
        $id = $this->sub('co-conc', 'provisional', 2900);
        // Someone confirmed it meanwhile.
        (new ConfirmContractedPriceUseCase())->confirm($id, 2900, 1, 'first');

        $this->expectException(StaleConfirmationException::class);
        // A second operator submits against the stale basis (still 'provisional').
        (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 2, 'second', ['plan_price_source' => 'provisional', 'plan_price_cents' => 2900]);
    }

    public function test_double_confirmation_is_idempotent(): void
    {
        $id = $this->sub('co-idem', 'provisional', 2900);
        (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 1, 'proof');
        $auditCount = DB::table('platform_audit_logs')->where('action', 'subscription.contracted_price_confirmed')->count();

        (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 1, 'proof again'); // same price

        $this->assertSame($auditCount, DB::table('platform_audit_logs')->where('action', 'subscription.contracted_price_confirmed')->count(), 'no double audit/operation');
    }

    public function test_confirmed_price_is_used_at_next_renewal_without_retroactive_billing(): void
    {
        $id = $this->sub('co-renew', 'provisional', 2900);

        // Before confirmation, renewal is deferred (no invoice).
        Artisan::call('billing:renew');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('company_id', Subscription::find($id)->company_id)->count(), 'unverified → deferred, no invoice');

        // Confirm, then renew → billed at the confirmed price, once.
        (new ConfirmContractedPriceUseCase())->confirm($id, 2500, 1, 'Signed contract');
        Subscription::find($id)->update(['current_period_end' => now()->subDays(2)]); // due again
        Artisan::call('billing:renew');

        $invoices = Invoice::withoutGlobalScopes()->where('company_id', Subscription::find($id)->company_id)->get();
        $this->assertCount(1, $invoices, 'exactly one invoice — no retroactive cascade');
        $this->assertSame(2500, (int) $invoices->first()->subtotal, 'renewal bills the confirmed price');
    }
}
