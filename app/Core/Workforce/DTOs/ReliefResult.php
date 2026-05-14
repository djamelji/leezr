<?php

namespace App\Core\Workforce\DTOs;

/**
 * Stub DTO for employer contribution reliefs (allègements de cotisations).
 *
 * Phase 5.2: Structure only — no actual RGDU computation.
 * The RGDU (Réduction Générale Dégressive Unifiée, ex-Fillon) formula
 * will be implemented in a future phase when all parameters are available.
 *
 * Each relief line represents one exoneration/reduction:
 *   - code: 'rgdu', 'exoneration_hs', 'zfu', etc.
 *   - label: human-readable name
 *   - employer_relief_cents: amount of employer contribution reduction
 */
readonly class ReliefResult
{
    /**
     * @param  int  $totalEmployerReliefCents  Total employer contribution reduction (in cents)
     * @param  array  $lines  Array of relief line arrays [{code, label, employer_relief_cents}]
     */
    public function __construct(
        public int $totalEmployerReliefCents,
        public array $lines,
    ) {}

    public function toArray(): array
    {
        return [
            'total_employer_relief_cents' => $this->totalEmployerReliefCents,
            'lines' => $this->lines,
        ];
    }

    /**
     * Create an empty stub (no reliefs applied).
     */
    public static function stub(): self
    {
        return new self(
            totalEmployerReliefCents: 0,
            lines: [],
        );
    }
}
