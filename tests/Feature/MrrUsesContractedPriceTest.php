<?php

namespace Tests\Feature;

use App\Core\Billing\BillingMetricsCalculationService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-591 — the Billing Overview MRR must reflect the CONTRACTED price (snapshot, ADR-587), not the
 * live public catalog price. Changing a plan's public price must NOT move the MRR of existing
 * subscribers (they keep their contracted price).
 */
class MrrUsesContractedPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        Plan::where('key', 'pro')->update(['price_monthly' => 2900]);
        Plan::where('key', 'business')->update(['price_monthly' => 7900]);
    }

    private function subscribe(string $slug, string $plan): void
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'market_key' => 'FR', 'plan_key' => $plan, 'jobdomain_key' => 'logistique']);
        Subscription::create([
            'company_id' => $c->id, 'plan_key' => $plan, 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_mrr_is_stable_when_public_price_changes(): void
    {
        $this->subscribe('co-pro', 'pro');       // contracted 29,00 €
        $this->subscribe('co-business', 'business'); // contracted 79,00 €

        $mrr1 = (new BillingMetricsCalculationService())->calculate()['mrr'];
        $this->assertSame(10800, $mrr1, 'MRR = 2900 + 7900 = 108,00 €');

        // Platform drops the public price of pro to 11,00 € — existing subscriber keeps 29,00 €.
        Plan::where('key', 'pro')->update(['price_monthly' => 1100]);

        $mrr2 = (new BillingMetricsCalculationService())->calculate()['mrr'];
        $this->assertSame(10800, $mrr2, 'MRR unchanged: reflects contracted prices, not the new public price');
    }
}
