<?php

namespace App\Core\Billing;

use App\Core\Markets\LegalStatus;
use App\Core\Models\Company;

/**
 * Tax computation and rate resolution.
 *
 * Rate resolution priority:
 *   1. Company's LegalStatus → is_vat_applicable + vat_rate
 *   2. Fallback: PlatformBillingPolicy.default_tax_rate_bps
 *
 * Modes (from policy):
 *   - 'none': no tax applied (returns 0)
 *   - 'exclusive': tax added on top of subtotal
 *   - 'inclusive': subtotal already includes tax (extract it)
 */
class TaxResolver
{
    /**
     * Resolve the tax rate in basis points for a company.
     *
     * Uses the company's legal status VAT rate when available,
     * falls back to the global platform default otherwise.
     *
     * @return int Tax rate in basis points (2000 = 20%)
     */
    public static function resolveRateBps(Company $company): int
    {
        if ($company->legal_status_key) {
            $legalStatus = LegalStatus::where('key', $company->legal_status_key)
                ->where('market_key', $company->market_key)
                ->first();

            if ($legalStatus) {
                if (! $legalStatus->is_vat_applicable) {
                    return 0;
                }

                if ($legalStatus->vat_rate !== null) {
                    return (int) round($legalStatus->vat_rate * 100);
                }
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
