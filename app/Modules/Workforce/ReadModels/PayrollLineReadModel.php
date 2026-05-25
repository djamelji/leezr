<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\PayrollLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PayrollLineReadModel
{
    public static function forRun(int $payrollRunId, int $perPage = 50): LengthAwarePaginator
    {
        return PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $payrollRunId)
            ->with('employee:id,first_name,last_name,employee_number')
            ->orderBy('employee_id')
            ->paginate($perPage);
    }

    public static function forEmployee(int $employeeId, int $companyId): \Illuminate\Support\Collection
    {
        return PayrollLine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with('payrollRun:id,period_start,period_end,status')
            ->orderByDesc('created_at')
            ->get();
    }

    public static function detail(int $lineId, int $companyId): ?array
    {
        $line = PayrollLine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['employee:id,first_name,last_name,employee_number', 'calculation', 'payrollRun:id,period_start,period_end,status,currency'])
            ->find($lineId);

        if (! $line) {
            return null;
        }

        $calc = $line->calculation;

        return [
            'id' => $line->id,
            'employee' => $line->employee ? [
                'id' => $line->employee->id,
                'first_name' => $line->employee->first_name,
                'last_name' => $line->employee->last_name,
                'employee_number' => $line->employee->employee_number,
            ] : null,
            'payroll_run' => $line->payrollRun ? [
                'id' => $line->payrollRun->id,
                'period_start' => $line->payrollRun->period_start?->toDateString(),
                'period_end' => $line->payrollRun->period_end?->toDateString(),
                'status' => $line->payrollRun->status,
                'currency' => $line->payrollRun->currency,
            ] : null,
            'worked_minutes' => $line->worked_minutes,
            'break_minutes' => $line->break_minutes,
            'total_overtime_minutes' => $line->total_overtime_minutes,
            'daily_overtime_minutes' => $line->daily_overtime_minutes,
            'weekly_overtime_minutes' => $line->weekly_overtime_minutes,
            'planned_minutes' => $line->planned_minutes,
            'leave_days_hundredths' => $line->leave_days_hundredths,
            'paid_leave_days_hundredths' => $line->paid_leave_days_hundredths,
            'unpaid_leave_days_hundredths' => $line->unpaid_leave_days_hundredths,
            'base_salary_cents' => $line->base_salary_cents,
            'gross_basis_cents' => $line->gross_basis_cents,
            'gross_breakdown' => $line->gross_breakdown,
            'compensation_snapshot' => $line->compensation_snapshot,
            'anomalies' => $line->anomalies,
            'calculation' => $calc ? [
                'id' => $calc->id,
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
            ] : null,
        ];
    }

    public static function summary(int $payrollRunId): array
    {
        $lines = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $payrollRunId)
            ->get();

        return [
            'employee_count' => $lines->count(),
            'total_worked_minutes' => $lines->sum('worked_minutes'),
            'total_overtime_minutes' => $lines->sum('total_overtime_minutes'),
            'total_leave_days_hundredths' => $lines->sum('leave_days_hundredths'),
            'total_paid_leave_hundredths' => $lines->sum('paid_leave_days_hundredths'),
            'total_unpaid_leave_hundredths' => $lines->sum('unpaid_leave_days_hundredths'),
            'total_gross_basis_cents' => $lines->sum('gross_basis_cents'),
            'avg_gross_basis_cents' => $lines->count() > 0
                ? (int) round($lines->avg('gross_basis_cents'))
                : 0,
            'lines_with_anomalies' => $lines->filter(fn ($l) => ! empty($l->anomalies))->count(),
        ];
    }
}
