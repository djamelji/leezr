<?php

namespace App\Core\Workforce\DTOs;

readonly class ContributionLine
{
    public function __construct(
        public string $code,
        public string $label,
        public string $category,
        public string $baseType,
        public int $baseCents,
        public int $employeeRateBps,
        public int $employerRateBps,
        public int $employeeCents,
        public int $employerCents,
        public ?string $bulletinGroup = null,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'category' => $this->category,
            'base_type' => $this->baseType,
            'base_cents' => $this->baseCents,
            'employee_rate_bps' => $this->employeeRateBps,
            'employer_rate_bps' => $this->employerRateBps,
            'employee_cents' => $this->employeeCents,
            'employer_cents' => $this->employerCents,
            'bulletin_group' => $this->bulletinGroup,
        ];
    }
}
