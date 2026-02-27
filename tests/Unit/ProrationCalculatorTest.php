<?php

namespace Tests\Unit;

use App\Core\Billing\ProrationCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure unit tests — no DB, no Laravel bootstrap.
 *
 * Covers the 5 mandatory scenarios + edge cases:
 *   1. Upgrade mid-period (net positive)
 *   2. Downgrade mid-period (net negative)
 *   3. Same plan different interval
 *   4. Change on period start (no proration)
 *   5. Change on period end (zero)
 */
class ProrationCalculatorTest extends TestCase
{
    /**
     * Scenario 1: Upgrade pro→business mid-month.
     * 30-day period, change on day 15 → 15 days remaining.
     * Credit = floor(15/30 × 2900) = floor(1450) = 1450
     * Charge = floor(15/30 × 7900) = floor(3950) = 3950
     * Net = 3950 - 1450 = 2500 (company owes)
     */
    public function test_upgrade_mid_period_net_positive(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 7900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-16'),
        );

        $this->assertEquals(30, $result['total_days']);
        $this->assertEquals(15, $result['days_remaining']);
        $this->assertEquals(1450, $result['credit']);
        $this->assertEquals(3950, $result['charge']);
        $this->assertEquals(2500, $result['net']);
    }

    /**
     * Scenario 2: Downgrade business→pro mid-month.
     * Net should be negative (wallet credit).
     */
    public function test_downgrade_mid_period_net_negative(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 7900,
            newPriceCents: 2900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-16'),
        );

        $this->assertEquals(15, $result['days_remaining']);
        $this->assertEquals(3950, $result['credit']);
        $this->assertEquals(1450, $result['charge']);
        $this->assertEquals(-2500, $result['net']);
    }

    /**
     * Scenario 3: Same plan, monthly→yearly interval change.
     * pro monthly (2900) → pro yearly (29000/12 ≈ 2416 per month, but we use yearly price).
     */
    public function test_same_plan_interval_change(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 2416,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-16'),
        );

        $this->assertEquals(15, $result['days_remaining']);
        // Credit = floor(15/30 × 2900) = 1450
        $this->assertEquals(1450, $result['credit']);
        // Charge = floor(15/30 × 2416) = floor(1208) = 1208
        $this->assertEquals(1208, $result['charge']);
        // Net = 1208 - 1450 = -242
        $this->assertEquals(-242, $result['net']);
    }

    /**
     * Scenario 4: Change on period start — full period, no proration credit.
     */
    public function test_change_on_period_start_no_proration(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 7900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-01'),
        );

        $this->assertEquals(30, $result['days_remaining']);
        $this->assertEquals(30, $result['total_days']);
        $this->assertEquals(0, $result['credit']);
        $this->assertEquals(7900, $result['charge']);
        $this->assertEquals(7900, $result['net']);
    }

    /**
     * Scenario 5: Change on period end — zero everything.
     */
    public function test_change_on_period_end_zero(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 7900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-31'),
        );

        $this->assertEquals(0, $result['days_remaining']);
        $this->assertEquals(0, $result['credit']);
        $this->assertEquals(0, $result['charge']);
        $this->assertEquals(0, $result['net']);
    }

    /**
     * Floor strategy: fractional cents are always rounded down.
     * 31-day period, 10 days remaining, price 2900.
     * floor(10/31 × 2900) = floor(935.48...) = 935
     */
    public function test_floor_proration_fractional(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 7900,
            periodStart: CarbonImmutable::parse('2026-01-01'),
            periodEnd: CarbonImmutable::parse('2026-02-01'),
            changeDate: CarbonImmutable::parse('2026-01-22'),
        );

        $this->assertEquals(31, $result['total_days']);
        $this->assertEquals(10, $result['days_remaining']);
        // floor(10/31 × 2900) = floor(935.48) = 935
        $this->assertEquals(935, $result['credit']);
        // floor(10/31 × 7900) = floor(2548.38) = 2548
        $this->assertEquals(2548, $result['charge']);
    }

    /**
     * Free plan (0 cents) — credit is 0, charge is 0.
     */
    public function test_free_plan_proration(): void
    {
        $result = ProrationCalculator::compute(
            oldPriceCents: 0,
            newPriceCents: 2900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-16'),
        );

        $this->assertEquals(0, $result['credit']);
        $this->assertEquals(1450, $result['charge']);
        $this->assertEquals(1450, $result['net']);
    }

    public function test_negative_price_throws(): void
    {
        $this->expectException(RuntimeException::class);

        ProrationCalculator::compute(
            oldPriceCents: -100,
            newPriceCents: 2900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-03-16'),
        );
    }

    public function test_change_date_outside_period_throws(): void
    {
        $this->expectException(RuntimeException::class);

        ProrationCalculator::compute(
            oldPriceCents: 2900,
            newPriceCents: 7900,
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEnd: CarbonImmutable::parse('2026-03-31'),
            changeDate: CarbonImmutable::parse('2026-04-05'),
        );
    }

    public function test_resolve_price_cents_monthly(): void
    {
        $planDef = ['price_monthly' => 29, 'price_yearly' => 290];

        $this->assertEquals(2900, ProrationCalculator::resolvePriceCents($planDef, 'monthly'));
    }

    public function test_resolve_price_cents_yearly(): void
    {
        $planDef = ['price_monthly' => 29, 'price_yearly' => 290];

        $this->assertEquals(29000, ProrationCalculator::resolvePriceCents($planDef, 'yearly'));
    }
}
