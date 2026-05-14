<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\TaxResult;

class TaxCalculator
{
    /**
     * PAS = taxable_income × tax_rate.
     * taxable_income = gross_total - contributions_employee + csg_non_deductible
     * Floor rounding (convention légale).
     * Pure — no DB access.
     */
    public static function compute(
        int $grossTotalCents,
        int $contributionsEmployeeCents,
        int $csgNonDeductibleCents,
        int $taxRateBps
    ): TaxResult {
        // Clamp tax rate to legal bounds: 0–4300 bps (0%–43%)
        $taxRateBps = max(0, min($taxRateBps, 4300));

        // Net imposable = gross - cotisations salariales + CSG non-déductible
        $taxableIncome = $grossTotalCents - $contributionsEmployeeCents + $csgNonDeductibleCents;
        $taxableIncome = max(0, $taxableIncome);

        // PAS: floor to lower euro (convention légale)
        // We compute in cents, then floor to nearest 100 (euro)
        $taxCentsExact = (int) floor($taxableIncome * $taxRateBps / 10000);

        return new TaxResult(
            taxableIncomeCents: $taxableIncome,
            taxRateBps: $taxRateBps,
            taxCents: $taxCentsExact,
        );
    }
}
