<?php

namespace App\Core\Workforce\Dsn;

/**
 * DSN monthly declaration payload — contains all structured data
 * needed to serialize into NEODES Phase 3 format.
 *
 * Pure DTO — readonly, no DB access.
 * Built from a validated/exported PayrollRun.
 *
 * Sprint 6.3 — ADR-521
 */
readonly class DsnPayload
{
    /**
     * @param  string  $declarationType  'mensuelle' (monthly) — only type supported
     * @param  string  $nature           '01' = DSN mensuelle
     * @param  string  $periodStart      Period start date (Y-m-d)
     * @param  string  $periodEnd        Period end date (Y-m-d)
     * @param  string  $fraction         '11' = single fraction (monthly)
     * @param  int     $payrollRunId     Source PayrollRun ID (audit trail)
     * @param  DsnCompanyData  $company  Establishment data (S21.G00.06)
     * @param  DsnEmployeeBlock[]  $employees  Per-employee blocks
     * @param  DsnCtpAggregate[]  $ctpAggregates  Aggregated CTP lines (S21.G00.22)
     * @param  string  $createdAt        Declaration generation timestamp (ISO 8601)
     */
    public function __construct(
        public string $declarationType,
        public string $nature,
        public string $periodStart,
        public string $periodEnd,
        public string $fraction,
        public int $payrollRunId,
        public DsnCompanyData $company,
        public array $employees,
        public array $ctpAggregates,
        public string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'declaration_type' => $this->declarationType,
            'nature' => $this->nature,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'fraction' => $this->fraction,
            'payroll_run_id' => $this->payrollRunId,
            'company' => [
                'company_id' => $this->company->companyId,
                'name' => $this->company->name,
                'siret' => $this->company->siret,
                'naf_code' => $this->company->nafCode,
            ],
            'employees_count' => count($this->employees),
            'employees' => array_map(fn (DsnEmployeeBlock $e) => $e->toArray(), $this->employees),
            'ctp_aggregates' => array_map(fn (DsnCtpAggregate $a) => $a->toArray(), $this->ctpAggregates),
            'created_at' => $this->createdAt,
        ];
    }
}
