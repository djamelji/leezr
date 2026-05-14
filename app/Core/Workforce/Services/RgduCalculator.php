<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\DTOs\RgduResult;

/**
 * Sprint 6.1: RGDU (Réduction Générale Dégressive Unifiée) calculator.
 *
 * Pure static service — ZERO DB access, ZERO side effects.
 *
 * Formula (art. L241-13 CSS, décrets 2024+):
 *   C = (T / 0.6) × (1.6 × SMIC_proratisé / rémunération_brute − 1)
 *   C clamped to [0, T]
 *   Relief = C × rémunération_brute
 *   Capped at: sum of eligible employer contributions
 *
 * ⚠️ INDICATIF — Validation expert-comptable requise.
 */
class RgduCalculator
{
    const FORMULA_VERSION = 'rgdu-v1';

    /**
     * Eligible contribution codes for RGDU reduction (art. L241-13 CSS).
     * These employer contributions can be reduced by the RGDU coefficient.
     */
    const DEFAULT_ELIGIBLE_CODES = [
        'urssaf_maladie',
        'at_mp',
        'allocations_familiales',
        'urssaf_vieillesse_plaf',
        'urssaf_vieillesse_deplaf',
        'fnal',
        'csa',
        'chomage',
        'retraite_t1',
        'ceg_t1',
        'cet',
    ];

    /**
     * Compute RGDU relief for a single payroll line.
     *
     * @param  int    $grossTotalCents           Rémunération brute mensuelle (en cents)
     * @param  int    $smicHourlyCents           SMIC horaire brut (en cents, ex: 1147)
     * @param  float  $contractedMonthlyHours    Heures contractuelles mensuelles (weekly_hours × 52/12)
     * @param  float  $overtimeHours             Heures supplémentaires dans le mois
     * @param  int    $coefficientTBps           Coefficient T maximal (en bps, ex: 3195 = 31.95%)
     * @param  ContributionResult  $contributions  Calculated contributions (to extract eligible amounts)
     * @param  array  $eligibleCodes             Contribution codes eligible for RGDU
     */
    public static function compute(
        int $grossTotalCents,
        int $smicHourlyCents,
        float $contractedMonthlyHours,
        float $overtimeHours,
        int $coefficientTBps,
        ContributionResult $contributions,
        array $eligibleCodes = [],
    ): RgduResult {
        if (empty($eligibleCodes)) {
            $eligibleCodes = self::DEFAULT_ELIGIBLE_CODES;
        }

        // Guard: zero gross = no relief
        if ($grossTotalCents <= 0) {
            return self::zeroResult($grossTotalCents, 0, $contractedMonthlyHours, $overtimeHours);
        }

        // Step 1: SMIC mensuel proratisé
        // For full-time: SMIC × 151.67
        // For part-time: SMIC × (weekly_hours × 52/12)
        // For overtime: add SMIC × overtime_hours
        $totalHours = $contractedMonthlyHours + $overtimeHours;
        $smicProratiseCents = (int) round($smicHourlyCents * $totalHours);

        // Step 2: Coefficient C
        // C = (T / 0.6) × (1.6 × SMIC_proratisé / rémunération_brute − 1)
        $ratio = $smicProratiseCents / $grossTotalCents;
        $rawCoefficient = ($coefficientTBps / 6000) * (1.6 * $ratio - 1);

        // Clamp to [0, T/10000]
        $coefficientDecimal = max(0, min($rawCoefficient, $coefficientTBps / 10000));
        $coefficientBps = (int) round($coefficientDecimal * 10000);

        // Step 3: Check eligibility (salary > 1.6 × SMIC → C = 0)
        $eligible = $coefficientBps > 0;

        if (! $eligible) {
            return self::zeroResult($grossTotalCents, $smicProratiseCents, $contractedMonthlyHours, $overtimeHours);
        }

        // Step 4: Preliminary relief = C × gross
        $preliminaryReliefCents = (int) round($grossTotalCents * $coefficientDecimal);

        // Step 5: Cap at eligible employer contributions
        $eligibleEmployerCents = self::sumEligibleEmployerContributions($contributions, $eligibleCodes);
        $reliefCents = min($preliminaryReliefCents, $eligibleEmployerCents);

        return new RgduResult(
            coefficientBps: $coefficientBps,
            smicProratiseCents: $smicProratiseCents,
            reliefCents: $reliefCents,
            eligibleEmployerContributionsCents: $eligibleEmployerCents,
            grossTotalCents: $grossTotalCents,
            contractedMonthlyHours: $contractedMonthlyHours,
            overtimeHours: $overtimeHours,
            eligible: true,
            formulaVersion: self::FORMULA_VERSION,
        );
    }

    /**
     * Sum employer contributions for eligible codes only.
     */
    private static function sumEligibleEmployerContributions(
        ContributionResult $contributions,
        array $eligibleCodes
    ): int {
        $total = 0;
        foreach ($contributions->lines as $line) {
            if (in_array($line->code, $eligibleCodes, true)) {
                $total += $line->employerCents;
            }
        }

        return $total;
    }

    private static function zeroResult(
        int $grossTotalCents,
        int $smicProratiseCents,
        float $contractedMonthlyHours,
        float $overtimeHours,
    ): RgduResult {
        return new RgduResult(
            coefficientBps: 0,
            smicProratiseCents: $smicProratiseCents,
            reliefCents: 0,
            eligibleEmployerContributionsCents: 0,
            grossTotalCents: $grossTotalCents,
            contractedMonthlyHours: $contractedMonthlyHours,
            overtimeHours: $overtimeHours,
            eligible: false,
            formulaVersion: self::FORMULA_VERSION,
        );
    }
}
