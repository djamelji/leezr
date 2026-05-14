<?php

namespace App\Core\Workforce\Dsn;

/**
 * Per-employee DSN data block — groups identity (S21.G00.30),
 * contract (S21.G00.40), payment (S21.G00.50), remuneration lines (S21.G00.51),
 * CSG/CRDS bases (S21.G00.78), and individual contributions (S21.G00.81).
 *
 * Pure DTO — readonly, no DB access.
 */
readonly class DsnEmployeeBlock
{
    /**
     * @param  DsnEmployeeData  $identity     Employee identity (NIR, name, address...)
     * @param  array  $contract               Contract data for S21.G00.40
     * @param  array  $payment                Payment data for S21.G00.50
     * @param  array  $remunerationLines      Remuneration breakdown for S21.G00.51
     * @param  array  $csgCrdsLines           CSG/CRDS base lines for S21.G00.78
     * @param  array  $contributionLines      Individual contribution lines for S21.G00.81
     * @param  int    $payrollLineId          Source PayrollLine ID (audit trail)
     */
    public function __construct(
        public DsnEmployeeData $identity,
        public array $contract,
        public array $payment,
        public array $remunerationLines,
        public array $csgCrdsLines,
        public array $contributionLines,
        public int $payrollLineId,
    ) {}

    public function toArray(): array
    {
        return [
            'employee_id' => $this->identity->employeeId,
            'payroll_line_id' => $this->payrollLineId,
            'identity' => [
                'nir' => $this->identity->nir,
                'last_name' => $this->identity->lastName,
                'first_name' => $this->identity->firstName,
            ],
            'contract' => $this->contract,
            'payment' => $this->payment,
            'remuneration_lines' => $this->remunerationLines,
            'csg_crds_lines' => $this->csgCrdsLines,
            'contribution_lines' => $this->contributionLines,
        ];
    }
}
