<?php

namespace App\Core\Workforce\DTOs;

/**
 * Sprint 6.1: RGDU (Réduction Générale Dégressive Unifiée) calculation result.
 *
 * Contains the computed coefficient, SMIC proratisé, and relief amount.
 * Pure DTO — no DB access.
 *
 * Formula (art. L241-13 CSS):
 *   C = (T / 0.6) × (1.6 × SMIC_proratisé / rémunération_brute − 1)
 *   C clamped to [0, T]
 *   Relief = C × rémunération_brute, capped at eligible employer contributions
 */
readonly class RgduResult
{
    public function __construct(
        public int $coefficientBps,
        public int $smicProratiseCents,
        public int $reliefCents,
        public int $eligibleEmployerContributionsCents,
        public int $grossTotalCents,
        public float $contractedMonthlyHours,
        public float $overtimeHours,
        public bool $eligible,
        public string $formulaVersion,
    ) {}

    public function toArray(): array
    {
        return [
            'coefficient_bps' => $this->coefficientBps,
            'smic_proratise_cents' => $this->smicProratiseCents,
            'relief_cents' => $this->reliefCents,
            'eligible_employer_contributions_cents' => $this->eligibleEmployerContributionsCents,
            'gross_total_cents' => $this->grossTotalCents,
            'contracted_monthly_hours' => round($this->contractedMonthlyHours, 2),
            'overtime_hours' => round($this->overtimeHours, 2),
            'eligible' => $this->eligible,
            'formula_version' => $this->formulaVersion,
        ];
    }
}
