<?php

namespace App\Core\Workforce\DTOs;

readonly class ContributionResult
{
    public function __construct(
        public array $lines,
        public int $totalEmployeeCents,
        public int $totalEmployerCents,
        public int $csgNonDeductibleCents,
    ) {}

    public function totalCostCents(): int
    {
        return $this->totalEmployerCents;
    }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (ContributionLine $l) => $l->toArray(), $this->lines),
            'total_employee_cents' => $this->totalEmployeeCents,
            'total_employer_cents' => $this->totalEmployerCents,
            'csg_non_deductible_cents' => $this->csgNonDeductibleCents,
        ];
    }
}
