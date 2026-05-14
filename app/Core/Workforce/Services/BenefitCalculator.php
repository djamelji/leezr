<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\BenefitResult;

class BenefitCalculator
{
    /**
     * Compute benefits from CompensationPlan.benefits JSON.
     * Each benefit: {code, label, amount_cents, frequency, taxable, is_cash}
     * Pure — no DB access.
     */
    public static function compute(array $benefits): BenefitResult
    {
        $taxable = 0;
        $nonCash = 0;
        $cash = 0;
        $lines = [];

        foreach ($benefits as $b) {
            $amount = (int) ($b['amount_cents'] ?? 0);
            $isTaxable = (bool) ($b['taxable'] ?? false);
            $isCash = (bool) ($b['is_cash'] ?? true);

            if ($isTaxable) {
                $taxable += $amount;
            }
            if ($isCash) {
                $cash += $amount;
            } else {
                $nonCash += $amount;
            }

            $lines[] = [
                'code' => $b['code'] ?? 'unknown',
                'label' => $b['label'] ?? $b['code'] ?? 'Unknown',
                'amount_cents' => $amount,
                'taxable' => $isTaxable,
                'is_cash' => $isCash,
            ];
        }

        return new BenefitResult(
            taxableBenefitsCents: $taxable,
            nonCashBenefitsCents: $nonCash,
            cashBenefitsCents: $cash,
            lines: $lines,
        );
    }
}
