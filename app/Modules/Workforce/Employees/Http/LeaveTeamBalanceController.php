<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-823 — team view of leave balances (read only) : the same cached ledger balances as the per-employee
 * view, limited to the reader's leave perimeter (view-all, or self + direct reports). No computation here.
 */
class LeaveTeamBalanceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['year' => 'sometimes|integer|min:2000|max:2100', 'leave_type_id' => 'sometimes|integer']);
        $visible = WorkforceAccess::hasViewAll($request, 'leave') ? null : WorkforceAccess::visibleEmployeeIds($request, 'leave');
        $rows = LeaveBalanceReadModel::forCompany(
            $request->attributes->get('company_id'),
            $request->query('year') ? (int) $request->query('year') : null,
            $request->query('leave_type_id') ? (int) $request->query('leave_type_id') : null,
        );
        if ($visible !== null) {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['employee_id'], $visible, true)));
        }

        return response()->json(['data' => $rows]);
    }
}
