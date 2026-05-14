<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\ContributionLine;
use App\Core\Workforce\DTOs\ContributionResult;

/**
 * Progressive regularization for social contributions (méthode URSSAF).
 *
 * Wraps ContributionCalculator — NEVER modifies it.
 * Pure computation — ZERO DB writes.
 *
 * Algorithm:
 *   1. Compute contributions on cumulative YTD amounts (months 1→M)
 *   2. Compute contributions on prior cumulative amounts (months 1→M-1) with CURRENT rules
 *   3. Delta = current month's regularized contribution
 *
 * When no prior data exists (month 1), delegates directly to ContributionCalculator.
 */
class RegularizationCalculator
{
    /**
     * @param  int        $currentMonthGrossCents   Gross for the current month only
     * @param  int        $plafondSSMonthlyCents     Monthly SS ceiling (same each month)
     * @param  int        $csgBaseMultiplierBps      CSG base multiplier (e.g. 9825 = 98.25%)
     * @param  array      $contributionRules         Contribution rules array
     * @param  array|null $priorCumuls               Prior YTD data from YtdCalculator::getPriorCumuls()
     * @return ContributionResult                    Regularized contributions for current month
     */
    public static function compute(
        int $currentMonthGrossCents,
        int $plafondSSMonthlyCents,
        int $csgBaseMultiplierBps,
        array $contributionRules,
        ?array $priorCumuls,
    ): ContributionResult {
        // No prior → month 1 or first run: simple monthly calculation
        if ($priorCumuls === null) {
            return ContributionCalculator::compute(
                $currentMonthGrossCents,
                $plafondSSMonthlyCents,
                $csgBaseMultiplierBps,
                $contributionRules,
            );
        }

        $priorMonthsCount = count($priorCumuls['months_included']);
        $currentMonthsCount = $priorMonthsCount + 1;

        $ytdGross = $priorCumuls['ytd_gross_total_cents'] + $currentMonthGrossCents;
        $ytdPlafond = $plafondSSMonthlyCents * $currentMonthsCount;
        $priorPlafond = $plafondSSMonthlyCents * $priorMonthsCount;

        // Compute cumulative (months 1→M) with current rules
        $cumulativeResult = ContributionCalculator::compute(
            $ytdGross,
            $ytdPlafond,
            $csgBaseMultiplierBps,
            $contributionRules,
        );

        // Recompute prior cumulative (months 1→M-1) with current rules
        // Using current rules ensures consistency even if rules changed mid-year
        $priorResult = ContributionCalculator::compute(
            $priorCumuls['ytd_gross_total_cents'],
            $priorPlafond,
            $csgBaseMultiplierBps,
            $contributionRules,
        );

        return self::deltaResults($cumulativeResult, $priorResult);
    }

    /**
     * Compute the delta between cumulative and prior results.
     * Each line: delta = cumulative - prior.
     */
    private static function deltaResults(
        ContributionResult $cumulative,
        ContributionResult $prior,
    ): ContributionResult {
        // Index prior lines by code
        $priorByCode = [];
        foreach ($prior->lines as $line) {
            $priorByCode[$line->code] = $line;
        }

        $regularizedLines = [];
        $totalEmployee = 0;
        $totalEmployer = 0;
        $csgNonDeductible = 0;

        foreach ($cumulative->lines as $cumulLine) {
            $priorLine = $priorByCode[$cumulLine->code] ?? null;

            $deltaBase = $cumulLine->baseCents - ($priorLine?->baseCents ?? 0);
            $deltaEmployee = $cumulLine->employeeCents - ($priorLine?->employeeCents ?? 0);
            $deltaEmployer = $cumulLine->employerCents - ($priorLine?->employerCents ?? 0);

            $regularizedLines[] = new ContributionLine(
                code: $cumulLine->code,
                label: $cumulLine->label,
                category: $cumulLine->category,
                baseType: $cumulLine->baseType,
                baseCents: $deltaBase,
                employeeRateBps: $cumulLine->employeeRateBps,
                employerRateBps: $cumulLine->employerRateBps,
                employeeCents: $deltaEmployee,
                employerCents: $deltaEmployer,
                bulletinGroup: $cumulLine->bulletinGroup,
            );

            $totalEmployee += $deltaEmployee;
            $totalEmployer += $deltaEmployer;

            if ($cumulLine->code === 'csg_non_deductible') {
                $csgNonDeductible = $deltaEmployee;
            }
        }

        return new ContributionResult(
            lines: $regularizedLines,
            totalEmployeeCents: $totalEmployee,
            totalEmployerCents: $totalEmployer,
            csgNonDeductibleCents: $csgNonDeductible,
        );
    }
}
