<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Modules\Workforce\ReadModels\PersonalHoursReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-787 — GET /workforce/me/hours : MY hours (week, payroll period or month, pending / validated).
 * Ownership only : the requester's own ACTIVE employee record in the active company ; no supervision
 * permission involved and no parameter that could target someone else. No record → 404.
 */
class EmployeeSelfHoursController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = Employee::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->where('user_id', $request->user()->id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->firstOrFail();

        return response()->json(PersonalHoursReadModel::forEmployee($request->attributes->get('company'), $employee));
    }
}
