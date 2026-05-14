<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class LeaveRequestReadModel
{
    public static function forEmployee(
        int $companyId,
        int $employeeId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return LeaveRequest::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->when($from, fn ($q) => $q->where('date_from', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('date_to', '<=', $to->toDateString()))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('leaveType:id,code,name')
            ->orderByDesc('date_from')
            ->paginate($perPage);
    }

    public static function pendingForApproval(
        int $companyId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return LeaveRequest::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->with([
                'employee:id,first_name,last_name,employee_number',
                'leaveType:id,code,name',
            ])
            ->orderBy('date_from')
            ->paginate($perPage);
    }

    public static function calendar(int $companyId, Carbon $from, Carbon $to): Collection
    {
        return LeaveRequest::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
            ->where('date_from', '<=', $to->toDateString())
            ->where('date_to', '>=', $from->toDateString())
            ->with([
                'employee:id,first_name,last_name',
                'leaveType:id,code,name',
            ])
            ->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee ? "{$r->employee->first_name} {$r->employee->last_name}" : null,
                'leave_type' => $r->leaveType?->name,
                'date_from' => $r->date_from->toDateString(),
                'date_to' => $r->date_to->toDateString(),
                'days_count' => $r->days_count_hundredths / 100,
                'status' => $r->status,
            ]);
    }

    public static function statistics(int $companyId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');

        $requests = LeaveRequest::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereYear('date_from', $year)
            ->selectRaw('status, COUNT(*) as count, SUM(days_count_hundredths) as total_days')
            ->groupBy('status')
            ->get();

        $stats = [];
        foreach ($requests as $row) {
            $stats[$row->status] = [
                'count' => (int) $row->count,
                'total_days_hundredths' => (int) $row->total_days,
            ];
        }

        return $stats;
    }
}
