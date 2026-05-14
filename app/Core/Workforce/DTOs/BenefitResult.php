<?php

namespace App\Core\Workforce\DTOs;

readonly class BenefitResult
{
    public function __construct(
        public int $taxableBenefitsCents,
        public int $nonCashBenefitsCents,
        public int $cashBenefitsCents,
        public array $lines,
    ) {}

    public function toArray(): array
    {
        return [
            'taxable_benefits_cents' => $this->taxableBenefitsCents,
            'non_cash_benefits_cents' => $this->nonCashBenefitsCents,
            'cash_benefits_cents' => $this->cashBenefitsCents,
            'lines' => $this->lines,
        ];
    }
}
