<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\TaxResult;

/**
 * Progressive regularization for PAS (prélèvement à la source).
 *
 * Wraps TaxCalculator — NEVER modifies it.
 * Pure computation — ZERO DB writes.
 *
 * Algorithm:
 *   1. Compute cumulative YTD taxable income (prior + current month)
 *   2. Apply CURRENT tax rate to full cumul → theoretical YTD tax
 *   3. Subtract actually paid prior tax → regularized monthly tax
 *
 * Handles rate changes mid-year: applies new rate to full cumul,
 * creating a catch-up (or refund) effect automatically.
 *
 * When no prior data exists (month 1), delegates directly to TaxCalculator.
 */
class TaxRegularizationCalculator
{
    /**
     * @param  int        $currentMonthGrossCents            Gross for current month
     * @param  int        $currentMonthContribEmployeeCents   Regularized employee contributions for current month
     * @param  int        $currentMonthCsgNonDeductibleCents  Regularized CSG non-déductible for current month
     * @param  int        $taxRateBps                         Current PAS rate in basis points
     * @param  array|null $priorCumuls                        Prior YTD data from YtdCalculator::getPriorCumuls()
     * @return TaxResult                                      Regularized PAS for current month
     */
    public static function compute(
        int $currentMonthGrossCents,
        int $currentMonthContribEmployeeCents,
        int $currentMonthCsgNonDeductibleCents,
        int $taxRateBps,
        ?array $priorCumuls,
    ): TaxResult {
        // No prior → month 1 or first run: simple monthly calculation
        if ($priorCumuls === null) {
            return TaxCalculator::compute(
                $currentMonthGrossCents,
                $currentMonthContribEmployeeCents,
                $currentMonthCsgNonDeductibleCents,
                $taxRateBps,
            );
        }

        // Clamp rate to legal bounds
        $taxRateBps = max(0, min($taxRateBps, 4300));

        // Build cumulative amounts
        $ytdGross = $priorCumuls['ytd_gross_total_cents'] + $currentMonthGrossCents;
        $ytdContribEmployee = $priorCumuls['ytd_contributions_employee_cents'] + $currentMonthContribEmployeeCents;

        $priorCsgNd = self::extractPriorCsgNonDeductible($priorCumuls);
        $ytdCsgNd = $priorCsgNd + $currentMonthCsgNonDeductibleCents;

        // Cumulative taxable income
        $ytdTaxable = max(0, $ytdGross - $ytdContribEmployee + $ytdCsgNd);

        // Apply current rate to full cumul (floor = convention légale)
        $ytdTax = (int) floor($ytdTaxable * $taxRateBps / 10000);

        // Delta = current month's regularized tax
        $priorTax = $priorCumuls['ytd_tax_cents'] ?? 0;
        $taxMonth = $ytdTax - $priorTax;

        // Monthly taxable income (for payslip reporting)
        $monthlyTaxable = max(0, $currentMonthGrossCents - $currentMonthContribEmployeeCents + $currentMonthCsgNonDeductibleCents);

        return new TaxResult(
            taxableIncomeCents: $monthlyTaxable,
            taxRateBps: $taxRateBps,
            taxCents: $taxMonth,
        );
    }

    /**
     * Extract prior cumulative CSG non-déductible from YTD contribution lines.
     */
    private static function extractPriorCsgNonDeductible(array $priorCumuls): int
    {
        foreach ($priorCumuls['ytd_contribution_lines'] ?? [] as $line) {
            if (($line['code'] ?? '') === 'csg_non_deductible') {
                return $line['ytd_employee_cents'] ?? 0;
            }
        }

        return 0;
    }
}
