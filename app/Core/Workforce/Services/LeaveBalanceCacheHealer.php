<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\LeaveBalanceCache;

/**
 * Self-heals the leave balance cache when drift is detected.
 *
 * Extracted from LeaveBalanceReadModel to maintain ReadModel purity.
 * Compares ledger-computed balance vs cached values and updates if different.
 */
class LeaveBalanceCacheHealer
{
    public static function healIfNeeded(int $companyId, int $employeeId, int $leaveTypeId, int $year, array $computed): void
    {
        $cache = LeaveBalanceCache::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('period_year', $year)
            ->first();

        if ($cache && $cache->cached_available !== $computed['available']) {
            $cache->update([
                'cached_accrued' => $computed['accrued'],
                'cached_reserved' => $computed['reserved'],
                'cached_consumed' => $computed['consumed'],
                'cached_adjusted' => $computed['adjusted'],
                'cached_available' => $computed['available'],
                'last_computed_at' => now(),
            ]);
        }
    }
}
