<?php

namespace App\Core\Workforce\Dsn;

/**
 * Aggregated CTP (Code Type Personnel) line for DSN bloc S21.G00.22.
 * One aggregate per CTP code — sums all employees' contributions.
 *
 * Pure DTO — readonly, no DB access.
 */
readonly class DsnCtpAggregate
{
    /**
     * @param  string  $ctpCode          URSSAF CTP code (e.g. '100', '260')
     * @param  string  $ctpLabel         Human-readable label
     * @param  int     $baseCents        Total base amount (sum across employees)
     * @param  int     $employeeCents    Total employee contribution
     * @param  int     $employerCents    Total employer contribution
     * @param  int     $employeeCount    Number of employees contributing
     */
    public function __construct(
        public string $ctpCode,
        public string $ctpLabel,
        public int $baseCents,
        public int $employeeCents,
        public int $employerCents,
        public int $employeeCount,
    ) {}

    public function toArray(): array
    {
        return [
            'ctp_code' => $this->ctpCode,
            'ctp_label' => $this->ctpLabel,
            'base_cents' => $this->baseCents,
            'employee_cents' => $this->employeeCents,
            'employer_cents' => $this->employerCents,
            'employee_count' => $this->employeeCount,
        ];
    }
}
