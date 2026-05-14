<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\LeaveBalanceCache;
use App\Core\Workforce\Services\LeaveLedger;

class LeaveBalanceReadModel
{
    /**
     * Get balance breakdown for a single employee, all leave types.
     * Uses cache with self-healing from ledger.
     */
    public static function forEmployee(int $companyId, int $employeeId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');

        $caches = LeaveBalanceCache::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('period_year', $year)
            ->with('leaveType:id,code,name')
            ->get();

        return $caches->map(fn (LeaveBalanceCache $cache) => [
            'leave_type_id' => $cache->leave_type_id,
            'leave_type_code' => $cache->leaveType?->code,
            'leave_type_name' => $cache->leaveType?->name,
            'period_year' => $cache->period_year,
            'accrued' => $cache->cached_accrued,
            'reserved' => $cache->cached_reserved,
            'consumed' => $cache->cached_consumed,
            'adjusted' => $cache->cached_adjusted,
            'available' => $cache->cached_available,
            'last_computed_at' => $cache->last_computed_at?->toIso8601String(),
        ])->toArray();
    }

    /**
     * Compute exact balance from ledger (source of truth) for one leave type.
     * Pure read-only — cache healing is delegated to LeaveBalanceCacheHealer.
     */
    public static function computeBalance(int $companyId, int $employeeId, int $leaveTypeId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');

        return LeaveLedger::computeBalance($companyId, $employeeId, $leaveTypeId, $year);
    }

    /**
     * Get all employee balances for a company (dashboard view).
     */
    public static function forCompany(int $companyId, ?int $year = null, ?int $leaveTypeId = null): array
    {
        $year ??= (int) now()->format('Y');

        $query = LeaveBalanceCache::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->with(['employee:id,first_name,last_name,employee_number', 'leaveType:id,code,name']);

        if ($leaveTypeId) {
            $query->where('leave_type_id', $leaveTypeId);
        }

        return $query->get()->map(fn (LeaveBalanceCache $cache) => [
            'employee_id' => $cache->employee_id,
            'employee_name' => $cache->employee ? "{$cache->employee->first_name} {$cache->employee->last_name}" : null,
            'employee_number' => $cache->employee?->employee_number,
            'leave_type_id' => $cache->leave_type_id,
            'leave_type_code' => $cache->leaveType?->code,
            'leave_type_name' => $cache->leaveType?->name,
            'accrued' => $cache->cached_accrued,
            'reserved' => $cache->cached_reserved,
            'consumed' => $cache->cached_consumed,
            'adjusted' => $cache->cached_adjusted,
            'available' => $cache->cached_available,
        ])->toArray();
    }
}
