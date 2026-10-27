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
        bool $canSeeCompensation = false,
    ): LengthAwarePaginator {
        // ADR-634 : le salaire n'est chargé que si l'utilisateur a workforce.compensation_read.
        $with = [
            'department:id,name',
            'jobRole:id,title',
            'manager:id,first_name,last_name',
        ];
        if ($canSeeCompensation) {
            $with['currentContract.compensationPlans'] = fn ($q) => $q->activeAt();
        } else {
            $with[] = 'currentContract:id,employee_id,contract_type,work_model_key,weekly_hours,start_date,status,is_current';
        }

        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($search, fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('employee_number', 'LIKE', "%{$search}%");
            }))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->with($with)
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public static function detail(int $companyId, int $employeeId, bool $canSeeCompensation = false): ?Employee
    {
        // ADR-634 : la compensation des contrats n'est chargée que sous workforce.compensation_read.
        $with = [
            'user:id,name,email',
            'department:id,name',
            'jobRole:id,title,default_hourly_rate_cents',
            'manager:id,first_name,last_name',
            'contracts' => fn ($q) => $q->orderByDesc('start_date'),
            'contracts.conventionCollective:id,idcc,short_name',
        ];
        if ($canSeeCompensation) {
            $with['contracts.compensationPlans'] = fn ($q) => $q->orderByDesc('effective_from');
        }

        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->with($with)
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
