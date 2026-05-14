<?php

namespace App\Core\Workforce\DTOs;

readonly class CalculationResult
{
    public function __construct(
        public string $ruleVersion,
        public int $plafondSSMonthlyCents,
        public int $grossTotalCents,
        public ContributionResult $contributions,
        public TaxResult $tax,
        public BenefitResult $benefits,
        public DeductionResult $deductions,
        public NetResult $net,
        public array $blockingAnomalies,
        public array $rulesApplied,
        public ?array $ruleSnapshot = null,
        public ?array $regularizationSnapshot = null,
        public ?int $netSocialCents = null,
        public ?ReliefResult $reliefs = null,
    ) {}

    public function toArray(): array
    {
        return [
            'rule_version' => $this->ruleVersion,
            'plafond_ss_monthly_cents' => $this->plafondSSMonthlyCents,
            'gross_total_cents' => $this->grossTotalCents,
            'contributions_employee_cents' => $this->contributions->totalEmployeeCents,
            'contributions_employer_cents' => $this->contributions->totalEmployerCents,
            'total_cost_employer_cents' => $this->net->totalCostEmployerCents,
            'taxable_income_cents' => $this->tax->taxableIncomeCents,
            'tax_cents' => $this->tax->taxCents,
            'net_before_tax_cents' => $this->net->netBeforeTaxCents,
            'net_payable_cents' => $this->net->netPayableCents,
            'net_social_cents' => $this->netSocialCents,
            'benefits_cents' => $this->benefits->taxableBenefitsCents,
            'deductions_cents' => $this->deductions->totalCents,
            'contribution_lines' => $this->contributions->toArray(),
            'tax_breakdown' => $this->tax->toArray(),
            'benefit_lines' => $this->benefits->toArray(),
            'deduction_lines' => $this->deductions->toArray(),
            'relief_lines' => $this->reliefs?->toArray() ?? ['total_employer_relief_cents' => 0, 'lines' => []],
            'blocking_anomalies' => $this->blockingAnomalies,
            'rules_applied' => $this->rulesApplied,
        ];
    }

    public function hasBlockingAnomalies(): bool
    {
        return ! empty($this->blockingAnomalies);
    }
}
