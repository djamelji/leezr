<?php

namespace App\Core\Billing;

use App\Core\Markets\Market;
use App\Core\Models\Company;

/**
 * Tax computation and rate resolution.
 *
 * Rate resolution (ADR-254):
 *   1. Company's Market → vat_rate_bps (country-level standard rate)
 *   2. Fallback: PlatformBillingPolicy.default_tax_rate_bps
 *
 * The rate is the standard VAT rate of the country (market).
 * The company's LegalStatus (SAS, auto-entrepreneur...) does NOT change the
 * rate we charge — it only indicates whether the company itself is VAT-registered
 * (is_vat_applicable), which is informational for invoice mentions.
 *
 * Modes (from policy):
 *   - 'exclusive': tax added on top of subtotal (B2B default)
 *   - 'inclusive': subtotal already includes tax (extract it)
 */
class TaxResolver
{
    /**
     * Resolve the tax rate in basis points for a company.
     *
     * Uses the company's market standard VAT rate.
     * Falls back to the global platform default if no market set.
     *
     * @return int Tax rate in basis points (2000 = 20%)
     */
    public static function resolveRateBps(Company $company): int
    {
        if ($company->market_key) {
            $market = Market::where('key', $company->market_key)->first();

            if ($market && $market->vat_rate_bps > 0) {
                return $market->vat_rate_bps;
            }
        }

        return PlatformBillingPolicy::instance()->default_tax_rate_bps ?? 0;
    }
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
            'inclusive' => static::computeInclusive($subtotal, $rateBps),
            default => static::computeExclusive($subtotal, $rateBps), // 'exclusive' is the B2B default
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
