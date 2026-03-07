<?php

namespace App\Core\Billing;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pure, deterministic proration calculator — no DB reads.
 *
 * All inputs are explicit, all outputs are reproducible.
 * Amounts in cents. Strategy: day_based with floor (company-favorable).
 *
 * Usage:
 *   $result = ProrationCalculator::compute(
 *       oldPriceCents:  2900,
 *       newPriceCents:  7900,
 *       periodStart:    CarbonImmutable::parse('2026-03-01'),
 *       periodEnd:      CarbonImmutable::parse('2026-03-31'),
 *       changeDate:     CarbonImmutable::parse('2026-03-15'),
 *   );
 *   // $result = ['credit' => ..., 'charge' => ..., 'net' => ..., 'days_remaining' => ..., 'total_days' => ...]
 */
class ProrationCalculator
{
    /**
     * Compute proration amounts for a mid-period plan change.
     *
     * @param  int             $oldPriceCents  Full-period price of the old plan (cents)
     * @param  int             $newPriceCents  Full-period price of the new plan (cents)
     * @param  CarbonImmutable $periodStart    Start of the current billing period
     * @param  CarbonImmutable $periodEnd      End of the current billing period
     * @param  CarbonImmutable $changeDate     Date the change takes effect
     * @return array{credit: int, charge: int, net: int, days_remaining: int, total_days: int}
     */
    public static function compute(
        int $oldPriceCents,
        int $newPriceCents,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $changeDate,
    ): array {
        // Validate inputs
        if ($oldPriceCents < 0 || $newPriceCents < 0) {
            throw new RuntimeException('Prices must be non-negative.');
        }

        if ($periodEnd->lte($periodStart)) {
            throw new RuntimeException('Period end must be after period start.');
        }

        if ($changeDate->lt($periodStart) || $changeDate->gt($periodEnd)) {
            throw new RuntimeException('Change date must be within the billing period.');
        }

        // Day-based proration (Carbon v3 returns float, cast to int)
        $totalDays = (int) round($periodStart->diffInDays($periodEnd));
        $daysRemaining = (int) round($changeDate->diffInDays($periodEnd));

        // Edge case: change on period start = full period, no proration needed
        if ($daysRemaining === $totalDays) {
            return [
                'credit' => 0,
                'charge' => $newPriceCents,
                'net' => $newPriceCents,
                'days_remaining' => $daysRemaining,
                'total_days' => $totalDays,
            ];
        }

        // Edge case: change on period end = no remaining time
        if ($daysRemaining === 0) {
            return [
                'credit' => 0,
                'charge' => 0,
                'net' => 0,
                'days_remaining' => 0,
                'total_days' => $totalDays,
            ];
        }

        // Credit for unused portion of old plan: floor(remaining / total × old_price)
        $credit = (int) floor($daysRemaining / $totalDays * $oldPriceCents);

        // Charge for remaining portion of new plan: floor(remaining / total × new_price)
        $charge = (int) floor($daysRemaining / $totalDays * $newPriceCents);

        // Net = what the company owes (positive) or is owed (negative)
        $net = $charge - $credit;

        return [
            'credit' => $credit,
            'charge' => $charge,
            'net' => $net,
            'days_remaining' => $daysRemaining,
            'total_days' => $totalDays,
        ];
    }

    /**
     * Resolve the effective price for a plan+interval combination.
     * Pure helper — takes plan definitions array, no DB call.
     *
     * @param  array  $planDef  Plan definition (from PlanRegistry::definitions())
     * @param  string $interval 'monthly' or 'yearly'
     * @return int    Price in cents
     */
    public static function resolvePriceCents(array $planDef, string $interval): int
    {
        // PlanRegistry::definitions() returns prices in dollars
        if ($interval === 'yearly') {
            return (int) round($planDef['price_yearly'] * 100);
        }

        return (int) round($planDef['price_monthly'] * 100);
    }
}
