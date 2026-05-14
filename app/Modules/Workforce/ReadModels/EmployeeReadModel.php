<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EmployeeReadModel
{
    public static function list(
        int $companyId,
        ?string $search = null,
        ?string $status = null,
        int $perPage = 15,
        string $sortBy = 'last_name',
        string $sortDir = 'asc',
    ): LengthAwarePaginator {
        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($search, fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('employee_number', 'LIKE', "%{$search}%");
            }))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->with(['currentContract.compensationPlans' => fn ($q) => $q->activeAt()])
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public static function detail(int $companyId, int $employeeId): ?Employee
    {
        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->with([
                'user:id,name,email',
                'contracts' => fn ($q) => $q->orderByDesc('start_date'),
                'contracts.compensationPlans' => fn ($q) => $q->orderByDesc('effective_from'),
            ])
            ->find($employeeId);
    }

    public static function countByStatus(int $companyId): array
    {
        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}
