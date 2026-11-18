<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\EmploymentContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * ADR-822 — company-wide register of employment contracts (read only). The contract state machine is
 * untouched : actions stay on the person file (create / activate / cancel). Compensation is never listed
 * here. The perimeter is the employee perimeter of the reader (null = whole company).
 */
final class ContractRegisterReadModel
{
    /** @param list<int>|null $visibleEmployeeIds */
    public static function list(int $companyId, ?array $visibleEmployeeIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $q = EmploymentContract::withoutGlobalScopes()->where('workforce_employment_contracts.company_id', $companyId)
            ->join('workforce_employees as e', 'e.id', '=', 'workforce_employment_contracts.employee_id')
            ->where('e.company_id', $companyId)
            ->when($visibleEmployeeIds !== null, fn ($q) => $q->whereIn('workforce_employment_contracts.employee_id', $visibleEmployeeIds))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('workforce_employment_contracts.status', $s))
            ->when($filters['contract_type'] ?? null, fn ($q, $t) => $q->where('workforce_employment_contracts.contract_type', $t))
            ->when($filters['ending_soon'] ?? false, fn ($q) => $q->whereNotNull('end_date')->whereBetween('end_date', [now()->toDateString(), now()->addDays(60)->toDateString()]))
            ->when($filters['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('e.first_name', 'like', $like)->orWhere('e.last_name', 'like', $like));
            })
            ->select([
                'workforce_employment_contracts.id', 'workforce_employment_contracts.employee_id', 'contract_type', 'work_model_key',
                'weekly_hours', 'start_date', 'end_date', 'probation_end_date', 'workforce_employment_contracts.status', 'is_current',
                'e.first_name', 'e.last_name', 'e.status as employee_status',
            ])
            ->orderByRaw("CASE workforce_employment_contracts.status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 WHEN 'suspended' THEN 2 WHEN 'terminated' THEN 3 ELSE 4 END")
            ->orderByDesc('start_date');

        $page = $q->paginate(min(max($perPage, 5), 100));
        $page->getCollection()->transform(fn ($c) => [
            'id' => $c->id, 'employee_id' => $c->employee_id, 'person_ref' => 'employee-'.$c->employee_id,
            'employee_name' => trim($c->first_name.' '.$c->last_name), 'employee_status' => $c->employee_status,
            'contract_type' => $c->contract_type, 'work_model_key' => $c->work_model_key, 'weekly_hours' => $c->weekly_hours,
            'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(),
            'probation_end_date' => $c->probation_end_date?->toDateString(), 'status' => $c->status, 'is_current' => (bool) $c->is_current,
        ]);

        return $page;
    }

    /** Counts by status in the same perimeter (for the filter chips). */
    public static function counts(int $companyId, ?array $visibleEmployeeIds): array
    {
        return EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)
            ->when($visibleEmployeeIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleEmployeeIds))
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }
}
