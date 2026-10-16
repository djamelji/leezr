<?php

namespace Tests\Feature;

use App\Core\Billing\BillingMetricsCalculationService;
use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing currency coherence (audit F2/F10).
 *
 * Plan prices are a single nominal figure applied in each market's own currency with
 * NO FX conversion (deliberate commercial simplification, ADR-570). The technical bug
 * fixed here is the scalar MRR silently mixing currencies: `mrr_by_currency` now keeps
 * each currency exact.
 */
class PricingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SystemSeeder::class);
    }

    public function test_reporting_currency_is_configured(): void
    {
        $this->assertNotEmpty(config('billing.reporting_currency'));
    }

    private function companyOnMarket(string $marketKey, string $slug): Company
    {
        return Company::create([
            'name' => $slug,
            'slug' => $slug,
            'jobdomain_key' => 'logistique',
            'market_key' => $marketKey,
            'plan_key' => 'pro',
        ]);
    }

    private function activeSub(Company $c): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'provider' => 'internal',
            'status' => 'active',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_mrr_breakdown_is_grouped_per_currency_not_mixed(): void
    {
        // Needs at least two distinct market currencies to be meaningful.
        $eur = Market::where('currency', 'EUR')->value('key');
        $gbp = Market::where('currency', 'GBP')->value('key');
        $this->assertNotNull($eur, 'Expected an EUR market seeded.');
        $this->assertNotNull($gbp, 'Expected a GBP market seeded.');

        $this->activeSub($this->companyOnMarket($eur, 'eur-co'));
        $this->activeSub($this->companyOnMarket($gbp, 'gbp-co'));

        $plans = Plan::all()->keyBy('key');
        $subs = Subscription::where('is_current', 1)->whereIn('status', ['active', 'past_due'])->get();

        $byCurrency = (new BillingMetricsCalculationService())->computeMrrByCurrency($subs, $plans);

        // Each currency kept separate — NOT summed into one mislabeled figure.
        $this->assertArrayHasKey('EUR', $byCurrency);
        $this->assertArrayHasKey('GBP', $byCurrency);
        $this->assertGreaterThan(0, $byCurrency['EUR']);
        $this->assertGreaterThan(0, $byCurrency['GBP']);
    }

    public function test_metrics_output_exposes_currency_and_breakdown(): void
    {
        $this->activeSub($this->companyOnMarket(
            Market::where('currency', 'EUR')->value('key'),
            'solo-co'
        ));

        $metrics = (new BillingMetricsCalculationService())->calculate();

        $this->assertSame(config('billing.reporting_currency', 'EUR'), $metrics['currency']);
        $this->assertArrayHasKey('mrr_by_currency', $metrics);
    }
}
