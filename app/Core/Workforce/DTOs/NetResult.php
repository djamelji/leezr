<?php

namespace App\Core\Workforce\DTOs;

readonly class NetResult
{
    public function __construct(
        public int $netBeforeTaxCents,
        public int $netPayableCents,
        public int $totalCostEmployerCents,
    ) {}

    public function toArray(): array
    {
        return [
            'net_before_tax_cents' => $this->netBeforeTaxCents,
            'net_payable_cents' => $this->netPayableCents,
            'total_cost_employer_cents' => $this->totalCostEmployerCents,
        ];
    }
}
