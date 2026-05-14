<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\TimesheetPeriod;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TimesheetPeriodReadModel
{
    public static function forEmployee(
        int $companyId,
        int $employeeId,
        ?int $year = null,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with('employee');

        if ($year) {
            $query->whereYear('period_start', $year);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('period_start')->paginate($perPage);
    }

    public static function forCompany(
        int $companyId,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('employee');

        if ($periodStart && $periodEnd) {
            $query->overlapping($periodStart, $periodEnd);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('period_start')->paginate($perPage);
    }

    public static function pendingApproval(int $companyId, int $perPage = 15): LengthAwarePaginator
    {
        return TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->submitted()
            ->with('employee')
            ->orderBy('submitted_at')
            ->paginate($perPage);
    }

    public static function statistics(int $companyId, string $periodStart, string $periodEnd): array
    {
        $timesheets = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->overlapping($periodStart, $periodEnd)
            ->get();

        $byStatus = $timesheets->groupBy('status')->map->count();
        $approvedOrLocked = $timesheets->whereIn('status', [
            TimesheetPeriod::STATUS_APPROVED,
            TimesheetPeriod::STATUS_LOCKED,
        ])->count();

        return [
            'total' => $timesheets->count(),
            'by_status' => $byStatus->toArray(),
            'total_employees' => $timesheets->pluck('employee_id')->unique()->count(),
            'completion_rate' => $timesheets->count() > 0
                ? round($approvedOrLocked / $timesheets->count() * 100, 1)
                : 0,
            'total_worked_minutes' => $timesheets->sum('total_worked_minutes'),
            'total_overtime_minutes' => $timesheets->sum('total_overtime_minutes'),
            'total_anomalies' => $timesheets->sum('anomaly_count'),
        ];
    }
}
