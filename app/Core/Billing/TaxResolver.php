<?php

namespace App\Core\Billing;

/**
 * Tax computation skeleton.
 *
 * V1: flat rate from PlatformBillingPolicy.default_tax_rate_bps.
 * Future: market-based rates, VAT exemptions, reverse charge.
 *
 * Modes (from policy):
 *   - 'none': no tax applied (returns 0)
 *   - 'exclusive': tax added on top of subtotal
 *   - 'inclusive': subtotal already includes tax (extract it)
 */
class TaxResolver
{
    /**
     * Compute tax amount in cents.
     *
     * @param int $subtotal Amount in cents (before tax for exclusive, total for inclusive)
     * @param int $rateBps Tax rate in basis points (2000 = 20%)
     * @return int Tax amount in cents (always >= 0)
     */
    public static function compute(int $subtotal, int $rateBps): int
    {
        if ($rateBps <= 0 || $subtotal <= 0) {
            return 0;
        }

        $policy = PlatformBillingPolicy::instance();

        return match ($policy->tax_mode) {
            'exclusive' => static::computeExclusive($subtotal, $rateBps),
            'inclusive' => static::computeInclusive($subtotal, $rateBps),
            default => 0, // 'none'
        };
    }

    /**
     * Exclusive: tax = subtotal × rate / 10000, floored.
     */
    private static function computeExclusive(int $subtotal, int $rateBps): int
    {
        return (int) floor($subtotal * $rateBps / 10000);
    }

    /**
     * Inclusive: extract tax from total.
     * tax = total - (total × 10000 / (10000 + rate)), floored.
     */
    private static function computeInclusive(int $total, int $rateBps): int
    {
        $netAmount = (int) floor($total * 10000 / (10000 + $rateBps));

        return $total - $netAmount;
    }
}
