<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\PlanningBoardReadModel;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use App\Modules\Workforce\Support\WeekStartResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — planning READS split out of ShiftController (list, week, calendar, board, availability, comparison, statistics). Same routes, permissions and payloads.
 */
class ShiftQueryController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\PlanningScope;
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals;

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $request->validate([
            'employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:draft,published,completed,cancelled',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(ShiftReadModel::forEmployee(
            companyId: $companyId,
            employeeId: $request->query('employee_id') ? (int) $request->query('employee_id') : 0,
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
            status: $request->query('status'),
            perPage: (int) $request->query('per_page', 15),
        ));
    }

    public function week(Request $request): JsonResponse
    {
        $request->validate(['date' => 'required|date']);

        // E3-B/B2 — début de semaine = préférence Company (0=dim..6=sam) résolue par WeekStartResolver,
        // et NON le lundi codé en dur de startOfWeek(). Cohérent avec la grille desktop et l'agenda mobile.
        $company = $request->attributes->get('company');
        $firstDay = $company ? WeekStartResolver::resolve($company) : Carbon::MONDAY;

        return response()->json(ShiftReadModel::forWeek(
            companyId: $request->attributes->get('company_id'),
            weekStart: Carbon::parse($request->query('date'))->startOfWeek($firstDay),
        ));
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(ShiftReadModel::calendar(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
        ));
    }

    /**
     * W-G4A — tableau Planning : shifts + absences (W-G3) + chevauchements, scopé + filtré serveur.
     */
    public function board(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'sometimes|integer',
            'employee_id' => 'sometimes|integer',
            'location_id' => 'sometimes|integer',
            'status' => 'sometimes|string',
        ]);

        return response()->json(PlanningBoardReadModel::board(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
            visibleIds: $this->planningScope($request),
            filters: [
                'department_id' => $request->query('department_id'),
                'employee_id' => $request->query('employee_id'),
                'location_id' => $request->query('location_id'),
                'status' => $request->query('status'),
            ],
        ));
    }

    /**
     * W-G4B — suggestions de collaborateurs disponibles pour une (ré)affectation. Scopé + borné.
     */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'exclude_shift_id' => 'sometimes|integer',
            'job_role_id' => 'sometimes|integer',
        ]);

        return response()->json(PlanningBoardReadModel::availability(
            companyId: $request->attributes->get('company_id'),
            start: Carbon::parse($request->query('start')),
            end: Carbon::parse($request->query('end')),
            excludeShiftId: $request->filled('exclude_shift_id') ? (int) $request->query('exclude_shift_id') : null,
            jobRoleId: $request->filled('job_role_id') ? (int) $request->query('job_role_id') : null,
            visibleIds: $this->planningScope($request),
        ));
    }

    /**
     * W-G4A — comparaison prévu (shifts) / réalisé (pointages), bornée + scopée. Informatif.
     */
    public function comparison(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'employee_id' => 'sometimes|integer',
        ]);

        return response()->json(PlanningBoardReadModel::comparison(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
            visibleIds: $this->planningScope($request),
            employeeId: $request->filled('employee_id') ? (int) $request->query('employee_id') : null,
        ));
    }

    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return response()->json(ShiftReadModel::statistics(
            companyId: $request->attributes->get('company_id'),
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
        ));
    }
}
