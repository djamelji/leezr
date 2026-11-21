<?php

namespace App\Modules\Workforce\Http\Concerns;

use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — the planning WRITE perimeter (W-G4B §8, ADR-816/817), written three times identically
 * (ShiftController, WeekTemplateController, ScheduledActivityController) : company-wide for HR / publishers
 * (planning « see all »), otherwise oneself + direct reports. view_all does not enter the write perimeter.
 */
trait PlanningScope
{
    /** null = whole company ; otherwise the employee ids the user may plan. */
    protected function planningScope(Request $request): ?array
    {
        return WorkforceAccess::hasViewAll($request, 'planning') ? null : WorkforceAccess::visibleEmployeeIds($request, 'employee');
    }

    /** 403 response when the employee is outside the planning perimeter, null when allowed. */
    protected function guardShiftScope(Request $request, int $employeeId): ?JsonResponse
    {
        $visible = $this->planningScope($request);
        if ($visible !== null && ! in_array($employeeId, $visible, true)) {
            return response()->json(['message' => 'Collaborateur hors de votre périmètre de gestion.'], 403);
        }

        return null;
    }
}
