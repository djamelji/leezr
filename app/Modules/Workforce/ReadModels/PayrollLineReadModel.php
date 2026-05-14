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
