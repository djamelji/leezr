<?php

namespace App\Core\Workforce\DTOs;

readonly class TaxResult
{
    public function __construct(
        public int $taxableIncomeCents,
        public int $taxRateBps,
        public int $taxCents,
    ) {}

    public function toArray(): array
    {
        return [
            'taxable_income_cents' => $this->taxableIncomeCents,
            'tax_rate_bps' => $this->taxRateBps,
            'tax_cents' => $this->taxCents,
        ];
    }
}
