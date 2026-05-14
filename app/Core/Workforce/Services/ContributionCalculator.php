<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\ContributionLine;
use App\Core\Workforce\DTOs\ContributionResult;

class ContributionCalculator
{
    /**
     * Compute all contributions for a given gross.
     * Pure and deterministic — no DB access.
     *
     * @param int $grossTotalCents
     * @param int $plafondSSCents
     * @param int $csgBaseMultiplierBps  e.g. 9825 = 98.25%
     * @param array $contributionRules   Each rule: [code, label, category, base_type, employee_rate_bps, employer_rate_bps]
     * @return ContributionResult
     */
    public static function compute(
        int $grossTotalCents,
        int $plafondSSCents,
        int $csgBaseMultiplierBps,
        array $contributionRules
    ): ContributionResult {
        $lines = [];
        $totalEmployee = 0;
        $totalEmployer = 0;
        $csgNonDeductible = 0;

        foreach ($contributionRules as $rule) {
            $baseType = $rule['base_type'] ?? 'deplafonne';
            $baseCents = self::resolveBase($grossTotalCents, $plafondSSCents, $csgBaseMultiplierBps, $baseType);

            $employeeRateBps = (int) ($rule['employee_rate_bps'] ?? 0);
            $employerRateBps = (int) ($rule['employer_rate_bps'] ?? 0);

            $employeeCents = (int) round($baseCents * $employeeRateBps / 10000);
            $employerCents = (int) round($baseCents * $employerRateBps / 10000);

            $line = new ContributionLine(
                code: $rule['code'],
                label: $rule['label'] ?? $rule['code'],
                category: $rule['category'] ?? 'other',
                baseType: $baseType,
                baseCents: $baseCents,
                employeeRateBps: $employeeRateBps,
                employerRateBps: $employerRateBps,
                employeeCents: $employeeCents,
                employerCents: $employerCents,
                bulletinGroup: $rule['bulletin_group'] ?? null,
            );

            $lines[] = $line;
            $totalEmployee += $employeeCents;
            $totalEmployer += $employerCents;

            // Track CSG non-deductible for tax calculation
            if ($rule['code'] === 'csg_non_deductible') {
                $csgNonDeductible = $employeeCents;
            }
        }

        return new ContributionResult(
            lines: $lines,
            totalEmployeeCents: $totalEmployee,
            totalEmployerCents: $totalEmployer,
            csgNonDeductibleCents: $csgNonDeductible,
        );
    }

    private static function resolveBase(int $grossCents, int $plafondSSCents, int $csgMultiplierBps, string $baseType): int
    {
        return match ($baseType) {
            'deplafonne' => $grossCents,
            'plafonne_ss', 'tranche_1' => min($grossCents, $plafondSSCents),
            'tranche_2' => max(0, min($grossCents, $plafondSSCents * 8) - $plafondSSCents),
            'csg_base' => (int) round($grossCents * $csgMultiplierBps / 10000),
            default => $grossCents,
        };
    }
}
