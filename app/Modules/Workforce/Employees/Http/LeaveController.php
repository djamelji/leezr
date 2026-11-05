<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\ApproveLeaveRequestUseCase;
use App\Modules\Workforce\UseCases\CancelLeaveRequestUseCase;
use App\Modules\Workforce\UseCases\RejectLeaveRequestUseCase;
use App\Modules\Workforce\UseCases\RequestLeaveUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $perPage = (int) $request->query('per_page', 15);
        $employeeId = $request->query('employee_id');

        if ($employeeId) {
            // ADR-634 : lire les congés d'un collaborateur exige « voir tout », soi-même, ou rapport direct.
            abort_unless(
                WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'leave'),
                403,
                'Not authorized to view this employee\'s leave.'
            );
            $leaves = LeaveRequestReadModel::forEmployee(
                companyId: $companyId,
                employeeId: (int) $employeeId,
                from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
                to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
                status: $request->query('status'),
                perPage: $perPage,
            );
        } else {
            // File d'approbation = vue de gestion : réservée aux capacités « voir tout » congés.
            abort_unless(
                WorkforceAccess::hasViewAll($request, 'leave'),
                403,
                'Not authorized to view the approval queue.'
            );
            $leaves = LeaveRequestReadModel::pendingForApproval($companyId, $perPage);
        }

        return response()->json($leaves);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->with(['leaveType', 'employee'])
            ->findOrFail($id);

        // W-D/W-G3 : lire une demande exige de pouvoir voir ce collaborateur (soi / rapport / vue globale).
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $leave->employee_id, 'leave'),
            403,
            'Not authorized to view this leave request.'
        );

        // W-G3 : voir une absence ≠ voir son MOTIF. Le motif n'est exposé qu'à soi-même ou à la vue
        // globale RH (approbateurs). Un manager en vue d'équipe (lecture seule) ne voit pas le motif.
        $data = $leave->toArray();
        if (! WorkforceAccess::hasViewAll($request, 'leave') && ! static::isSelf($request, $leave->employee_id)) {
            $data['reason'] = null;
            $data['reason_hidden'] = true;
        }

        return response()->json($data);
    }

    private static function isSelf(Request $request, int $employeeId): bool
    {
        return optional(WorkforceAccess::requesterEmployee($request))->id === $employeeId;
    }

    /**
     * Lot 3 §7 (ADR-716) — shifts PUBLIÉS impactés par une demande de congé, présentés au décideur
     * AVANT approbation. Lecture seule : ne modifie ni les shifts ni le solde ; aucun bannissement
     * automatique. Réutilise le modèle Shift (pas de projection parallèle). Aucun motif médical ici.
     */
    public function impactedShifts(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $leave = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->findOrFail($id);

        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $leave->employee_id, 'leave'),
            403,
            'Not authorized to view this leave request.'
        );

        $shifts = \App\Core\Workforce\Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $leave->employee_id)
            ->where('status', \App\Core\Workforce\Shift::STATUS_PUBLISHED)
            ->whereDate('date', '>=', $leave->date_from->toDateString())
            ->whereDate('date', '<=', $leave->date_to->toDateString())
            ->orderBy('start_at')
            ->get(['id', 'date', 'start_at', 'end_at', 'work_location_id', 'status']);

        return response()->json([
            'leave_id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'status' => $leave->status,
            'impacted_count' => $shifts->count(),
            'shifts' => $shifts->map(fn ($s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'start' => $s->start_at?->toIso8601String(),
                'end' => $s->end_at?->toIso8601String(),
                'work_location_id' => $s->work_location_id,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'leave_type_id' => 'required|integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'days_count_hundredths' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:1000',
        ]);

        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($validated['employee_id']);

        try {
            return response()->json(app(RequestLeaveUseCase::class)->execute(
                employee: $employee,
                leaveTypeId: $validated['leave_type_id'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                daysCountHundredths: $validated['days_count_hundredths'],
                reason: $validated['reason'] ?? null,
            ), 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_note' => 'nullable|string|max:1000']);
        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        // W-G3 : périmètre manager — on ne peut approuver que les demandes de collaborateurs visibles.
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $leaveRequest->employee_id, 'leave'),
            403,
            'Not authorized to approve this leave request.'
        );

        try {
            return response()->json(app(ApproveLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_note' => 'nullable|string|max:1000']);
        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        // W-G3 : périmètre manager — on ne peut refuser que les demandes de collaborateurs visibles.
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $leaveRequest->employee_id, 'leave'),
            403,
            'Not authorized to reject this leave request.'
        );

        try {
            return response()->json(app(RejectLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['cancellation_reason' => 'nullable|string|max:1000']);
        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            return response()->json(app(CancelLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                cancellationReason: $validated['cancellation_reason'] ?? null,
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'sometimes|integer',
            'leave_type_id' => 'sometimes|integer',
            'employee_id' => 'sometimes|integer',
            'statuses' => 'sometimes|array',
        ]);

        // W-G3 : scope manager — vue globale RH (leave_view_all / leave_approve) sinon soi + rapports.
        $visibleIds = WorkforceAccess::hasViewAll($request, 'leave')
            ? null
            : WorkforceAccess::visibleEmployeeIds($request, 'leave');

        return response()->json(LeaveRequestReadModel::calendar(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
            visibleIds: $visibleIds,
            filters: [
                'department_id' => $request->query('department_id'),
                'leave_type_id' => $request->query('leave_type_id'),
                'employee_id' => $request->query('employee_id'),
                'statuses' => $request->query('statuses'),
            ],
        ));
    }

    public function balances(Request $request, int $employeeId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        return response()->json(
            LeaveBalanceReadModel::forEmployee($companyId, $employeeId, $request->query('year') ? (int) $request->query('year') : null)
        );
    }

    public function statistics(Request $request): JsonResponse
    {
        return response()->json(LeaveRequestReadModel::statistics(
            $request->attributes->get('company_id'),
            $request->query('year') ? (int) $request->query('year') : null,
        ));
    }
}
