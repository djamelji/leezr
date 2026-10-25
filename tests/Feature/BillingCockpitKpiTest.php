<?php

namespace Tests\Feature;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-629 (LOT 7 cockpit). The Platform financial cockpit must surface BLOCKED RENEWALS (ADR-593
 * tariff review: unreliable contracted price) as a distinct KPI — separate from dunning (overdue
 * invoices) and failed payments. These renewals are deferred, never billed (anti-retro ADR-575).
 */
class BillingCockpitKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Kpi Co', 'slug' => 'kpi-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, ?string $source): void
    {
        Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => $source,
            'current_period_start' => now()->subMonths(5), 'current_period_end' => now()->subMonths(5)->addMonth(),
        ]);
    }

    public function test_provisional_subscription_counts_as_blocked_renewal(): void
    {
        $c = $this->company();
        $this->sub($c, 'provisional'); // Leezr-Logistics-like

        $risk = PlatformBillingWidgetsReadService::riskDataset('company', $c->id);

        $this->assertSame(1, $risk['blocked_renewals'], 'provisional price → blocked renewal');
        $this->assertArrayHasKey('pending_dunning', $risk);
        $this->assertArrayHasKey('failed_payments_7d', $risk);
    }

    public function test_reliable_subscription_is_not_a_blocked_renewal(): void
    {
        $c = $this->company();
        $this->sub($c, 'subscribed'); // reliable contracted price

        $risk = PlatformBillingWidgetsReadService::riskDataset('company', $c->id);

        $this->assertSame(0, $risk['blocked_renewals'], 'reliable price → not blocked');
    }
}
