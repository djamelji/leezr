<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\PayrollYtdSnapshot;
use App\Core\Workforce\Services\YtdCalculator;
use Illuminate\Support\Facades\DB;

class ComputePayrollCalculationsUseCase
{
    public function __construct(
        private PayrollCalculationEngine $engine
    ) {}

    public function execute(PayrollRun $run, int $actorId): PayrollRun
    {
        // Guard: status must be computed
        if ($run->status !== PayrollRun::STATUS_COMPUTED) {
            throw new \DomainException("Cannot compute calculations: PayrollRun status is '{$run->status}', expected 'computed'.");
        }

        // Guard: module enabled globally
        if (! ModuleGate::isEnabledGlobally('workforce_payroll')) {
            throw new \DomainException('Module workforce_payroll is not enabled for this company.');
        }

        DB::transaction(function () use ($run, $actorId) {
            // Delete existing calculations for idempotent recalculation
            PayrollCalculation::withoutGlobalScopes()
                ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
                ->delete();

            // Calculate each line
            $results = $this->engine->calculateRun($run);

            $createdCalculations = [];

            foreach ($results as $lineId => $result) {
                $createdCalculations[] = PayrollCalculation::create([
                    'company_id' => $run->company_id,
                    'payroll_line_id' => $lineId,
                    'rule_version' => $result->ruleVersion,
                    'plafond_ss_monthly_cents' => $result->plafondSSMonthlyCents,
                    'gross_total_cents' => $result->grossTotalCents,
                    'contributions_employee_cents' => $result->contributions->totalEmployeeCents,
                    'contributions_employer_cents' => $result->contributions->totalEmployerCents,
                    'total_cost_employer_cents' => $result->net->totalCostEmployerCents,
                    'taxable_income_cents' => $result->tax->taxableIncomeCents,
                    'tax_cents' => $result->tax->taxCents,
                    'net_before_tax_cents' => $result->net->netBeforeTaxCents,
                    'net_payable_cents' => $result->net->netPayableCents,
                    'benefits_cents' => $result->benefits->taxableBenefitsCents,
                    'deductions_cents' => $result->deductions->totalCents,
                    'contribution_lines' => $result->contributions->toArray(),
                    'tax_breakdown' => $result->tax->toArray(),
                    'benefit_lines' => $result->benefits->toArray(),
                    'deduction_lines' => $result->deductions->toArray(),
                    'net_social_cents' => $result->netSocialCents,
                    'relief_lines' => $result->reliefs?->toArray(),
                    'blocking_anomalies' => $result->hasBlockingAnomalies() ? $result->blockingAnomalies : null,
                    'calculation_snapshot' => $this->buildSnapshot($result, $run, $lineId),
                    'status' => PayrollCalculation::STATUS_CALCULATED,
                    'calculated_at' => now(),
                    'calculated_by' => $actorId,
                ]);
            }

            // YTD snapshot per employee (Phase 4.2 — ADR-505)
            $this->persistYtdSnapshots($createdCalculations, $run);
        });

        $run->refresh();

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payroll_calculations.computed',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'rule_version' => PayrollCalculationEngine::CALC_VERSION,
                    'employee_count' => $run->employee_count,
                ],
            ],
        );

        return $run;
    }

    /**
     * Persist YTD snapshots for each employee in the created calculations.
     * Uses updateOrCreate for idempotence on (company, employee, year, month).
     */
    private function persistYtdSnapshots(array $calculations, PayrollRun $run): void
    {
        $fiscalYear = (int) date('Y', strtotime($run->period_start));
        $periodMonth = (int) date('n', strtotime($run->period_start));

        // Group calculations by employee (via payroll line)
        $employeeIds = [];
        foreach ($calculations as $calc) {
            $line = $calc->payrollLine()->withoutGlobalScopes()->first();
            if ($line) {
                $employeeIds[$line->employee_id] = $calc;
            }
        }

        foreach ($employeeIds as $employeeId => $calc) {
            $ytdData = YtdCalculator::compute(
                $run->company_id,
                $employeeId,
                $fiscalYear,
                $periodMonth,
                includeCalculated: true,
            );

            PayrollYtdSnapshot::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $run->company_id,
                    'employee_id' => $employeeId,
                    'fiscal_year' => $fiscalYear,
                    'period_month' => $periodMonth,
                ],
                $ytdData,
            );
        }
    }

    private function buildSnapshot($result, PayrollRun $run, int $lineId): array
    {
        // Sprint 6.2: Include gross_breakdown overtime ventilation in snapshot
        $line = PayrollLine::withoutGlobalScopes()->find($lineId);
        $grossBreakdown = $line?->gross_breakdown;

        $inputData = [
            'gross_total_cents' => $result->grossTotalCents,
            'plafond_ss_monthly_cents' => $result->plafondSSMonthlyCents,
        ];

        // Enrich input with overtime ventilation from gross_breakdown (if available)
        if ($grossBreakdown) {
            $inputData['overtime_25_cents'] = $grossBreakdown['overtime_25_cents'] ?? null;
            $inputData['overtime_50_cents'] = $grossBreakdown['overtime_50_cents'] ?? null;
            $inputData['overtime_daily_cents'] = $grossBreakdown['overtime_daily_cents'] ?? null;
            $inputData['overtime_25_hours'] = $grossBreakdown['overtime_25_hours'] ?? null;
            $inputData['overtime_50_hours'] = $grossBreakdown['overtime_50_hours'] ?? null;
            $inputData['overtime_daily_hours'] = $grossBreakdown['overtime_daily_hours'] ?? null;
            $inputData['base_hours'] = $grossBreakdown['base_hours'] ?? null;
            $inputData['total_hours'] = $grossBreakdown['total_hours'] ?? null;
            $inputData['overtime_cents'] = $grossBreakdown['overtime_cents'] ?? null;
            $inputData['base_salary_cents'] = $grossBreakdown['base_salary_cents'] ?? null;
        }

        $snapshot = [
            'snapshot_version' => 'calc-snapshot-v3',
            'calculated_at' => now()->toIso8601String(),
            'rule_version' => $result->ruleVersion,
            'disclaimer' => 'Calcul indicatif — non exhaustif — validation expert-comptable requise',
            'input' => $inputData,
            'rules_applied' => $result->rulesApplied,
            'result' => [
                'contributions_employee_cents' => $result->contributions->totalEmployeeCents,
                'contributions_employer_cents' => $result->contributions->totalEmployerCents,
                'taxable_income_cents' => $result->tax->taxableIncomeCents,
                'tax_cents' => $result->tax->taxCents,
                'net_before_tax_cents' => $result->net->netBeforeTaxCents,
                'net_payable_cents' => $result->net->netPayableCents,
                'total_cost_employer_cents' => $result->net->totalCostEmployerCents,
            ],
            'blocking_anomalies' => $result->blockingAnomalies,
        ];

        // Merge resolver snapshot (CC context, company override context, resolver_version)
        if ($result->ruleSnapshot) {
            $snapshot['convention_collective'] = $result->ruleSnapshot['convention_collective'] ?? null;
            $snapshot['company_override'] = $result->ruleSnapshot['company_override'] ?? null;
            $snapshot['resolver_version'] = $result->ruleSnapshot['resolver_version'] ?? null;
        }

        // Merge regularization snapshot (Phase 4.3 — progressive regularization)
        if ($result->regularizationSnapshot) {
            $snapshot['regularization'] = $result->regularizationSnapshot;
        }

        // Phase 5.2: Net social + reliefs summary (bulletin display data)
        $snapshot['net_social_cents'] = $result->netSocialCents;
        $snapshot['relief_summary'] = $result->reliefs?->toArray() ?? ['total_employer_relief_cents' => 0, 'lines' => []];

        return $snapshot;
    }
}
