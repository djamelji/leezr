<?php

namespace App\Core\Workforce\DTOs;

readonly class DeductionResult
{
    public function __construct(
        public int $totalCents,
        public array $lines,
    ) {}

    public function toArray(): array
    {
        return [
            'total_cents' => $this->totalCents,
            'lines' => $this->lines,
        ];
    }
}
