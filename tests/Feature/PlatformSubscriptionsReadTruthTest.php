<?php

namespace Tests\Feature;

use App\Core\Billing\ReadModels\PlatformBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-631 (LOT 7B — financial truth on the Platform subscriptions list). The estimated next amount
 * must reflect the CONTRACTED price snapshot (plan_price_cents), never the public catalog price when
 * a negotiated price exists; and a subscription whose price is unreliable (ADR-593) must be flagged
 * renewal_blocked so the estimate is not read as a guaranteed upcoming charge.
 */
class PlatformSubscriptionsReadTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'slug' => 'co-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, ?int $cents, ?string $source): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => $cents, 'plan_price_source' => $source,
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_estimate_uses_contracted_price_not_public_price(): void
    {
        // Negotiated 1500 (reliable), public plan is 2900 — the estimate must show 1500, not 2900.
        $this->sub($this->company(), 1500, 'plan_change');

        $row = PlatformBillingReadService::subscriptions()->items()[0];

        $this->assertSame(1500, $row->estimated_next_amount, 'negotiated contracted price must win over public');
        $this->assertFalse($row->renewal_blocked, 'reliable price → not blocked');
    }

    public function test_provisional_price_is_flagged_renewal_blocked(): void
    {
        $this->sub($this->company(), 2900, 'provisional');

        $row = PlatformBillingReadService::subscriptions()->items()[0];

        $this->assertTrue($row->renewal_blocked, 'provisional source → renewal held, must be flagged');
    }

    public function test_cancelled_unreliable_subscription_is_not_flagged(): void
    {
        $c = $this->company();
        $s = $this->sub($c, 2900, 'provisional');
        $s->forceFill(['status' => 'cancelled', 'is_current' => false])->save();

        $row = PlatformBillingReadService::subscriptions()->items()[0];

        $this->assertFalse($row->renewal_blocked, 'not current / not active → nothing to renew, not flagged');
    }
}
