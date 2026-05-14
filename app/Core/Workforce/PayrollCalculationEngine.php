<?php

namespace App\Core\Workforce;

use App\Core\Models\Company;
use App\Core\Workforce\DTOs\CalculationResult;
use App\Core\Workforce\DTOs\NetResult;
use App\Core\Workforce\DTOs\ReliefResult;
use App\Core\Workforce\Services\BenefitCalculator;
use App\Core\Workforce\Services\DeductionCalculator;
use App\Core\Workforce\Services\NetAggregator;
use App\Core\Workforce\Services\NetSocialCalculator;
use App\Core\Workforce\Services\PayrollRuleResolver;
use App\Core\Workforce\Services\RegularizationCalculator;
use App\Core\Workforce\Services\RgduCalculator;
use App\Core\Workforce\Services\TaxRegularizationCalculator;
use App\Core\Workforce\Services\YtdCalculator;
use Carbon\Carbon;

class PayrollCalculationEngine
{
    const CALC_VERSION = 'payroll-calc-v2';

    public function __construct(
        private PayrollRuleResolver $ruleResolver
    ) {}

    /**
     * Full Brut→Net calculation for a single PayrollLine.
     * Returns a CalculationResult DTO (no persistence).
     *
     * @param  array|null $priorCumuls  Prior YTD data for progressive regularization (Phase 4.3)
     */
    public function calculate(PayrollLine $line, ?array $priorCumuls = null): CalculationResult
    {
        $company = Company::find($line->company_id);
        $marketKey = $company?->market_key ?? 'FR';
        $periodEnd = Carbon::parse($line->payrollRun->period_end);

        // Step 1: Resolve CC from contract (via compensation_snapshot.contract_id)
        $contractId = $line->compensation_snapshot['contract_id'] ?? null;
        $conventionCollectiveId = null;
        if ($contractId) {
            $contract = EmploymentContract::withoutGlobalScopes()->find($contractId);
            $conventionCollectiveId = $contract?->convention_collective_id;
        }

        // Step 1b: Resolve rules with full 3-level chain (Market → CC → Company Override)
        $contributionRules = $this->ruleResolver->resolveContributionsWithCC(
            $marketKey, $periodEnd, $conventionCollectiveId, $line->company_id
        );
        $plafondSS = $this->ruleResolver->resolvePlafondSS($marketKey, $periodEnd);
        $csgMultiplier = $this->ruleResolver->resolveCsgBaseMultiplier($marketKey, $periodEnd);
        $taxRateBps = $this->ruleResolver->resolveTaxRate($line->company_id, $line->employee_id, $periodEnd);

        // Step 2: Benefits (from CompensationPlan snapshot)
        $compensationSnapshot = $line->compensation_snapshot ?? [];
        $benefitsRaw = $compensationSnapshot['benefits'] ?? [];
        $benefits = BenefitCalculator::compute($benefitsRaw);

        // Step 3: Gross total = gross_basis + taxable_benefits (NO double counting)
        $grossBasis = $line->gross_basis_cents;
        $grossTotal = $grossBasis + $benefits->taxableBenefitsCents;

        // Step 4: Contributions — progressive regularization (Phase 4.3)
        // RegularizationCalculator delegates to ContributionCalculator when priorCumuls is null
        $contributions = RegularizationCalculator::compute(
            $grossTotal, $plafondSS, $csgMultiplier, $contributionRules, $priorCumuls
        );

        // Step 5: Tax (PAS) — progressive regularization (Phase 4.3)
        // TaxRegularizationCalculator delegates to TaxCalculator when priorCumuls is null
        $tax = TaxRegularizationCalculator::compute(
            $grossTotal,
            $contributions->totalEmployeeCents,
            $contributions->csgNonDeductibleCents,
            $taxRateBps,
            $priorCumuls
        );

        // Step 6: Deductions (stub Phase 3.1 — empty)
        $deductions = DeductionCalculator::compute([]);

        // Step 7: Net aggregation
        $net = NetAggregator::aggregate($grossTotal, $contributions, $tax, $benefits, $deductions);

        // Step 8: Detect blocking anomalies
        $blockingAnomalies = $this->detectAnomalies($net, $contributionRules, $taxRateBps, $plafondSS, $company);

        // Step 9: Build rules_applied snapshot (enriched with source_level + resolution_chain)
        $rulesApplied = array_map(fn ($r) => [
            'code' => $r['code'] ?? $r['rule_key'] ?? 'unknown',
            'rule_key' => $r['rule_key'] ?? $r['code'] ?? 'unknown',
            'base_type' => $r['base_type'] ?? 'deplafonne',
            'employee_rate_bps' => $r['employee_rate_bps'] ?? 0,
            'employer_rate_bps' => $r['employer_rate_bps'] ?? 0,
            'source_level' => $r['source_level'] ?? 'market_law',
            'source_ref' => $r['source_ref'] ?? $r['source'] ?? null,
            'resolution_chain' => $r['resolution_chain'] ?? ['market_law'],
        ], $contributionRules);

        // Step 10: Full resolver snapshot (for calculation_snapshot auditability)
        $ruleSnapshot = $this->ruleResolver->snapshotWithCC(
            $marketKey, $periodEnd, $conventionCollectiveId, $line->company_id
        );

        // Step 11: Regularization snapshot (Phase 4.3)
        $regularizationSnapshot = $this->buildRegularizationSnapshot($priorCumuls, $grossTotal, $contributions, $tax);

        // Step 12: Net social — display value for bulletin (Phase 5.2 — ADR-513)
        $netSocialCents = NetSocialCalculator::compute($grossTotal, $contributions);

        // Step 13: RGDU employer contribution reliefs (Sprint 6.1 — ADR-520)
        $reliefs = $this->computeRgduReliefs($line, $grossTotal, $contributions, $marketKey, $periodEnd, $company, $blockingAnomalies);

        return new CalculationResult(
            ruleVersion: self::CALC_VERSION,
            plafondSSMonthlyCents: $plafondSS,
            grossTotalCents: $grossTotal,
            contributions: $contributions,
            tax: $tax,
            benefits: $benefits,
            deductions: $deductions,
            net: $net,
            blockingAnomalies: $blockingAnomalies,
            rulesApplied: $rulesApplied,
            ruleSnapshot: $ruleSnapshot,
            regularizationSnapshot: $regularizationSnapshot,
            netSocialCents: $netSocialCents,
            reliefs: $reliefs,
        );
    }

    /**
     * Preview: read-only, returns array. ZERO DB writes, ZERO audit.
     */
    public function preview(PayrollLine $line): array
    {
        return $this->calculate($line)->toArray();
    }

    /**
     * Batch calculation for all lines in a PayrollRun.
     * Returns array of [payroll_line_id => CalculationResult].
     *
     * Fetches prior YTD cumuls per employee for progressive regularization (Phase 4.3).
     */
    public function calculateRun(PayrollRun $run): array
    {
        $results = [];
        $lines = $run->lines()->with('payrollRun')->get();

        // Determine fiscal year and period month from run
        $fiscalYear = (int) date('Y', strtotime($run->period_start));
        $periodMonth = (int) date('n', strtotime($run->period_start));

        // Cache prior cumuls per employee (avoid duplicate DB lookups)
        $priorCumulsByEmployee = [];

        foreach ($lines as $line) {
            $employeeId = $line->employee_id;

            if (! isset($priorCumulsByEmployee[$employeeId])) {
                $priorCumulsByEmployee[$employeeId] = YtdCalculator::getPriorCumuls(
                    $run->company_id,
                    $employeeId,
                    $fiscalYear,
                    $periodMonth,
                );
            }

            $results[$line->id] = $this->calculate($line, $priorCumulsByEmployee[$employeeId]);
        }

        return $results;
    }

    /**
     * Build regularization snapshot for audit trail (Phase 4.3).
     * Returns null if no prior cumuls (month 1 / no regularization).
     */
    private function buildRegularizationSnapshot(
        ?array $priorCumuls,
        int $grossTotal,
        DTOs\ContributionResult $contributions,
        DTOs\TaxResult $tax,
    ): ?array {
        if ($priorCumuls === null) {
            return null;
        }

        return [
            'regularization_method' => 'progressive',
            'prior_cumuls' => [
                'months_included' => $priorCumuls['months_included'],
                'ytd_gross_total_cents' => $priorCumuls['ytd_gross_total_cents'],
                'ytd_contributions_employee_cents' => $priorCumuls['ytd_contributions_employee_cents'],
                'ytd_contributions_employer_cents' => $priorCumuls['ytd_contributions_employer_cents'],
                'ytd_tax_cents' => $priorCumuls['ytd_tax_cents'],
            ],
            'current_month' => [
                'gross_total_cents' => $grossTotal,
                'contributions_employee_cents' => $contributions->totalEmployeeCents,
                'contributions_employer_cents' => $contributions->totalEmployerCents,
                'tax_cents' => $tax->taxCents,
            ],
        ];
    }

    /**
     * Compute RGDU reliefs for a payroll line (Sprint 6.1).
     * Returns ReliefResult (may be empty if rules missing or salary > 1.6 SMIC).
     *
     * @param  array  &$blockingAnomalies  Passed by reference — adds anomaly if RGDU rules missing
     */
    private function computeRgduReliefs(
        PayrollLine $line,
        int $grossTotalCents,
        DTOs\ContributionResult $contributions,
        string $marketKey,
        Carbon $periodEnd,
        ?Company $company,
        array &$blockingAnomalies,
    ): ReliefResult {
        // Resolve RGDU meta-rules
        $smicHoraire = $this->ruleResolver->resolveSmicHoraire($marketKey, $periodEnd);
        $averageHeadcount = $company?->average_headcount;
        $coefficientT = $this->ruleResolver->resolveRgduCoefficientT($marketKey, $periodEnd, $averageHeadcount);
        $eligibleCodes = $this->ruleResolver->resolveRgduEligibleCodes($marketKey, $periodEnd);

        // If SMIC or coefficient T not found → blocking anomaly, return stub
        if ($smicHoraire === null || $coefficientT === null) {
            $blockingAnomalies[] = [
                'type' => 'missing_rgdu_rule',
                'severity' => 'blocking',
                'message' => 'RGDU meta-rules (smic_horaire_cents or rgdu_coefficient_t) not found for market',
            ];

            return ReliefResult::stub();
        }

        // Extract hours from gross_breakdown (Sprint 6.2)
        $grossBreakdown = $line->gross_breakdown ?? [];
        $baseHours = (float) ($grossBreakdown['base_hours'] ?? 0);
        $overtimeHours = (float) ($grossBreakdown['overtime_hours'] ?? 0);

        // Fallback: if base_hours not available, compute from compensation snapshot
        if ($baseHours <= 0) {
            $weeklyHours = (float) ($line->compensation_snapshot['weekly_hours'] ?? 35);
            $baseHours = $weeklyHours * 52 / 12;
        }

        // Compute RGDU
        $rgduResult = RgduCalculator::compute(
            grossTotalCents: $grossTotalCents,
            smicHourlyCents: $smicHoraire,
            contractedMonthlyHours: $baseHours,
            overtimeHours: $overtimeHours,
            coefficientTBps: $coefficientT,
            contributions: $contributions,
            eligibleCodes: $eligibleCodes,
        );

        if ($rgduResult->reliefCents <= 0) {
            return ReliefResult::stub();
        }

        return new ReliefResult(
            totalEmployerReliefCents: $rgduResult->reliefCents,
            lines: [
                [
                    'code' => 'rgdu',
                    'label' => 'Réduction générale dégressive unifiée',
                    'employer_relief_cents' => $rgduResult->reliefCents,
                    'rgdu_detail' => $rgduResult->toArray(),
                ],
            ],
        );
    }

    private function detectAnomalies(
        NetResult $net,
        array $contributionRules,
        int $taxRateBps,
        int $plafondSS,
        ?Company $company
    ): array {
        $anomalies = [];

        if ($net->netPayableCents < 0) {
            $anomalies[] = [
                'type' => 'negative_net',
                'severity' => 'blocking',
                'message' => "Net payable négatif: {$net->netPayableCents} cents",
            ];
        }

        if (empty($contributionRules)) {
            $anomalies[] = [
                'type' => 'missing_rule',
                'severity' => 'blocking',
                'message' => 'No contribution rules found for market',
            ];
        }

        if ($taxRateBps < 0 || $taxRateBps > 4300) {
            $anomalies[] = [
                'type' => 'invalid_tax_rate',
                'severity' => 'blocking',
                'message' => "Tax rate {$taxRateBps} bps is outside legal bounds (0-4300)",
            ];
        }

        if ($plafondSS <= 0) {
            $anomalies[] = [
                'type' => 'missing_rule',
                'severity' => 'blocking',
                'message' => 'Plafond SS not found or zero',
            ];
        }

        $currency = $company?->currency ?? 'EUR';
        $marketKey = $company?->market_key ?? 'FR';
        if ($marketKey === 'FR' && $currency !== 'EUR') {
            $anomalies[] = [
                'type' => 'unsupported_currency',
                'severity' => 'blocking',
                'message' => "Currency {$currency} is not supported for FR market contributions",
            ];
        }

        return $anomalies;
    }
}
