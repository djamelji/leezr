<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\LeaveType;
use Illuminate\Support\Collection;

class LeaveTypeReadModel
{
    public static function forCompany(int $companyId): Collection
    {
        return LeaveType::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'description', 'accrual_mode',
                    'annual_entitlement_hundredths', 'max_balance_hundredths',
                    'carry_over_hundredths', 'requires_approval', 'is_paid',
                    'is_system', 'sort_order']);
    }

    public static function allForCompany(int $companyId): Collection
    {
        return LeaveType::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->get();
    }
}
