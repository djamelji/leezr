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
        ?int $departmentId = null,
        ?int $jobRoleId = null,
        ?int $managerId = null,
        ?string $contractType = null,
        ?array $visibleIds = null,
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

        $allowedSort = ['last_name', 'first_name', 'email', 'employee_number', 'status', 'hire_date'];
        $sortBy = in_array($sortBy, $allowedSort, true) ? $sortBy : 'last_name';
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';

        return static::filteredQuery($companyId, $search, $status, $departmentId, $jobRoleId, $managerId, $contractType, $visibleIds)
            ->with($with)
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    /**
     * W-G2 (ADR-642) — query collaborateurs filtrée + scopée, partagée par la liste et l'export.
     * `visibleIds` (scope manager) : null = aucune restriction (RH), [] = aucun collaborateur visible.
     *
     * @param  array<int>|null  $visibleIds
     */
    public static function filteredQuery(
        int $companyId,
        ?string $search = null,
        ?string $status = null,
        ?int $departmentId = null,
        ?int $jobRoleId = null,
        ?int $managerId = null,
        ?string $contractType = null,
        ?array $visibleIds = null,
    ): Builder {
        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($search, fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('employee_number', 'LIKE', "%{$search}%");
            }))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->when($jobRoleId, fn (Builder $q) => $q->where('job_role_id', $jobRoleId))
            ->when($managerId, fn (Builder $q) => $q->where('manager_id', $managerId))
            ->when($contractType, fn (Builder $q) => $q->whereHas('currentContract', fn (Builder $c) => $c->where('contract_type', $contractType)))
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('id', $visibleIds));
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
