<?php

namespace Tests\Unit;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\TaxResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_none_mode_returns_zero(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'none']);

        $this->assertEquals(0, TaxResolver::compute(10000, 2000));
    }

    public function test_exclusive_20_percent(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'exclusive']);

        // 100.00€ × 20% = 20.00€ = 2000 cents
        $this->assertEquals(2000, TaxResolver::compute(10000, 2000));
    }

    public function test_exclusive_floors_fractional_cents(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'exclusive']);

        // 33 cents × 20% = 6.6 → floor → 6
        $this->assertEquals(6, TaxResolver::compute(33, 2000));
    }

    public function test_inclusive_20_percent(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'inclusive']);

        // Total 12000 inclusive of 20%
        // Net = floor(12000 × 10000 / 12000) = floor(10000) = 10000
        // Tax = 12000 - 10000 = 2000
        $this->assertEquals(2000, TaxResolver::compute(12000, 2000));
    }

    public function test_zero_rate_returns_zero(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'exclusive']);

        $this->assertEquals(0, TaxResolver::compute(10000, 0));
    }

    public function test_zero_subtotal_returns_zero(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'exclusive']);

        $this->assertEquals(0, TaxResolver::compute(0, 2000));
    }
}
