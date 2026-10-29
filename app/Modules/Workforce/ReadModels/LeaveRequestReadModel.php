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

    /**
     * W-G3 (ADR-643) — événements calendrier des congés, scopés + filtrés côté serveur.
     *
     * Sécurité : `visibleIds` (null = vue globale RH ; sinon soi + rapports directs). Le MOTIF n'est
     * JAMAIS exposé ici (vues d'équipe). Statuts par défaut = en attente + approuvés + consommés
     * (une demande refusée n'apparaît pas comme une absence confirmée ; disponible en vue Historique).
     *
     * @param  array<int>|null  $visibleIds
     * @param  array<string,mixed>  $filters  department_id/leave_type_id/employee_id/statuses[]
     */
    public static function calendar(int $companyId, Carbon $from, Carbon $to, ?array $visibleIds = null, array $filters = []): Collection
    {
        $statuses = $filters['statuses'] ?? [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED];
        $statuses = array_values(array_intersect($statuses, LeaveRequest::STATUSES));

        $colors = [
            LeaveRequest::STATUS_PENDING => 'warning',
            LeaveRequest::STATUS_APPROVED => 'success',
            LeaveRequest::STATUS_CONSUMED => 'info',
            LeaveRequest::STATUS_REJECTED => 'error',
            LeaveRequest::STATUS_CANCELLED => 'secondary',
        ];

        return LeaveRequest::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('status', $statuses)
            ->where('date_from', '<=', $to->toDateString())
            ->where('date_to', '>=', $from->toDateString())
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when(! empty($filters['leave_type_id']), fn ($q) => $q->where('leave_type_id', (int) $filters['leave_type_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', (int) $filters['department_id'])))
            ->with([
                'employee:id,first_name,last_name,department_id',
                'leaveType:id,code,name',
            ])
            ->orderBy('date_from')
            ->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee ? "{$r->employee->first_name} {$r->employee->last_name}" : null,
                'department_id' => $r->employee?->department_id,
                'leave_type' => $r->leaveType?->name,
                'leave_type_code' => $r->leaveType?->code,
                'date_from' => $r->date_from->toDateString(),
                'date_to' => $r->date_to->toDateString(),
                'days_count' => $r->days_count_hundredths / 100,
                'status' => $r->status,
                'color' => $colors[$r->status] ?? 'secondary',
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
