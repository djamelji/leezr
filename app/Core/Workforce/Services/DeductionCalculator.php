<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\DeductionResult;

class DeductionCalculator
{
    /**
     * Compute deductions (acomptes, saisies, avances).
     * Each deduction: {code, label, amount_cents}
     * Stub for Phase 3.1 — will be enriched later.
     * Pure — no DB access.
     */
    public static function compute(array $deductions): DeductionResult
    {
        $total = 0;
        $lines = [];

        foreach ($deductions as $d) {
            $amount = (int) ($d['amount_cents'] ?? 0);
            $total += $amount;

            $lines[] = [
                'code' => $d['code'] ?? 'unknown',
                'label' => $d['label'] ?? $d['code'] ?? 'Unknown',
                'amount_cents' => $amount,
            ];
        }

        return new DeductionResult(
            totalCents: $total,
            lines: $lines,
        );
    }
}
