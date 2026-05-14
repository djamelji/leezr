<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\PayrollCalculation;

class PayrollCalculationReadModel
{
    /**
     * Get calculation detail for a single payroll line.
     */
    public static function forLine(int $payrollLineId): ?array
    {
        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $payrollLineId)
            ->first();

        if (! $calc) {
            return null;
        }

        return [
            'id' => $calc->id,
            'payroll_line_id' => $calc->payroll_line_id,
            'rule_version' => $calc->rule_version,
            'status' => $calc->status,
            'gross_total_cents' => $calc->gross_total_cents,
            'contributions_employee_cents' => $calc->contributions_employee_cents,
            'contributions_employer_cents' => $calc->contributions_employer_cents,
            'total_cost_employer_cents' => $calc->total_cost_employer_cents,
            'taxable_income_cents' => $calc->taxable_income_cents,
            'tax_cents' => $calc->tax_cents,
            'net_before_tax_cents' => $calc->net_before_tax_cents,
            'net_payable_cents' => $calc->net_payable_cents,
            'benefits_cents' => $calc->benefits_cents,
            'deductions_cents' => $calc->deductions_cents,
            'contribution_lines' => $calc->contribution_lines,
            'tax_breakdown' => $calc->tax_breakdown,
            'blocking_anomalies' => $calc->blocking_anomalies,
            'calculated_at' => $calc->calculated_at?->toIso8601String(),
        ];
    }

    /**
     * Get all calculations for a payroll run.
     */
    public static function forRun(int $payrollRunId): array
    {
        return PayrollCalculation::withoutGlobalScopes()
            ->whereHas('payrollLine', fn ($q) => $q->where('payroll_run_id', $payrollRunId))
            ->with('payrollLine:id,employee_id,gross_basis_cents')
            ->get()
            ->map(fn (PayrollCalculation $calc) => [
                'payroll_line_id' => $calc->payroll_line_id,
                'employee_id' => $calc->payrollLine?->employee_id,
                'status' => $calc->status,
                'gross_total_cents' => $calc->gross_total_cents,
                'contributions_employee_cents' => $calc->contributions_employee_cents,
                'contributions_employer_cents' => $calc->contributions_employer_cents,
                'tax_cents' => $calc->tax_cents,
                'net_payable_cents' => $calc->net_payable_cents,
                'total_cost_employer_cents' => $calc->total_cost_employer_cents,
                'blocking_anomalies' => $calc->blocking_anomalies,
            ])
            ->toArray();
    }

    /**
     * Aggregate totals for a payroll run.
     */
    public static function runTotals(int $payrollRunId): array
    {
        $calcs = PayrollCalculation::withoutGlobalScopes()
            ->whereHas('payrollLine', fn ($q) => $q->where('payroll_run_id', $payrollRunId))
            ->get();

        return [
            'count' => $calcs->count(),
            'gross_total_cents' => $calcs->sum('gross_total_cents'),
            'contributions_employee_cents' => $calcs->sum('contributions_employee_cents'),
            'contributions_employer_cents' => $calcs->sum('contributions_employer_cents'),
            'tax_cents' => $calcs->sum('tax_cents'),
            'net_payable_cents' => $calcs->sum('net_payable_cents'),
            'total_cost_employer_cents' => $calcs->sum('total_cost_employer_cents'),
            'blocking_anomalies_count' => $calcs->filter(fn ($c) => ! empty($c->blocking_anomalies))->count(),
        ];
    }
}
