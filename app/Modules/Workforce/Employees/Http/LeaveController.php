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
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals; // ADR-848

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $perPage = (int) $request->query('per_page', 15);
        $employeeId = $request->query('employee_id');

        if ($employeeId) {
            // ADR-634 : lire les congés d'un collaborateur exige « voir tout », soi-même, ou rapport direct.
            $this->guardLeave($request, (int) $employeeId, 'Not authorized to view this employee\'s leave.');
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
            abort_unless(WorkforceAccess::hasViewAll($request, 'leave'), 403, 'Not authorized to view the approval queue.');
            $leaves = LeaveRequestReadModel::pendingForApproval($companyId, $perPage);
        }

        return response()->json($leaves);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $leave = $this->leave($request, $id)->load(['leaveType', 'employee']);

        // W-D/W-G3 : lire une demande exige de pouvoir voir ce collaborateur (soi / rapport / vue globale).
        $this->guardLeave($request, $leave->employee_id, 'Not authorized to view this leave request.');

        // W-G3 : voir une absence ≠ voir son MOTIF. Le motif n'est exposé qu'à soi-même ou à la vue
        // globale RH (approbateurs). Un manager en vue d'équipe (lecture seule) ne voit pas le motif.
        $data = $leave->toArray();
        if (! WorkforceAccess::hasViewAll($request, 'leave') && ! static::isSelf($request, $leave->employee_id)) {
            $data['reason'] = null;
            $data['reason_hidden'] = true;
        }

        return response()->json($data);
    }

    /** ADR-848 : a leave request of the company (tenant-explicit) — was repeated in every action. */
    private function leave(Request $request, int $id): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
    }

    /** W-D/W-G3 : acting on a request needs to see that person (self / report / see all) — 403 with the action's message. */
    private function guardLeave(Request $request, int $employeeId, string $message): void
    {
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employeeId, 'leave'), 403, $message);
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
        $leave = $this->leave($request, $id);

        $this->guardLeave($request, $leave->employee_id, 'Not authorized to view this leave request.');

        return response()->json(\App\Modules\Workforce\ReadModels\LeaveRequestReadModel::impactedShifts($leave)); // ADR-848
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
            'hours_count_hundredths' => 'nullable|integer|min:1',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'reason' => 'nullable|string|max:1000',
        ]);

        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($validated['employee_id']);
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employee->id, 'leave'), 403); // ADR-780 : self or report only

        return $this->domain(fn () => app(RequestLeaveUseCase::class)->execute(
                employee: $employee,
                leaveTypeId: $validated['leave_type_id'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                daysCountHundredths: $validated['days_count_hundredths'],
                reason: $validated['reason'] ?? null,
                startTime: $validated['start_time'] ?? null,
                endTime: $validated['end_time'] ?? null,
                hoursCountHundredths: $validated['hours_count_hundredths'] ?? null,
            ), 201);
    }

    /**
     * Lot 3 (ADR-717) — suggestion de décompte (jours/heures) pour une fenêtre d'absence, selon le contrat.
     * Lecture seule, non contraignante : le formulaire pré-remplit, le décideur peut surcharger.
     */
    public function deductionPreview(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($validated['employee_id']);
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employee->id, 'leave'), 403);

        $contract = $employee->currentContract;
        if (! $contract) {
            return response()->json(['message' => 'Employee has no active contract.'], 422);
        }

        return response()->json(app(\App\Modules\Workforce\Services\LeaveDeductionCalculator::class)->suggest(
            $contract,
            $validated['date_from'],
            $validated['date_to'],
            $validated['start_time'] ?? null,
            $validated['end_time'] ?? null,
        ));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_note' => 'nullable|string|max:1000']);
        $leaveRequest = $this->leave($request, $id);

        // W-G3 : périmètre manager — on ne peut approuver que les demandes de collaborateurs visibles.
        $this->guardLeave($request, $leaveRequest->employee_id, 'Not authorized to approve this leave request.');

        return $this->domain(fn () => app(ApproveLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            ));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_note' => 'nullable|string|max:1000']);
        $leaveRequest = $this->leave($request, $id);

        // W-G3 : périmètre manager — on ne peut refuser que les demandes de collaborateurs visibles.
        $this->guardLeave($request, $leaveRequest->employee_id, 'Not authorized to reject this leave request.');

        return $this->domain(fn () => app(RejectLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            ));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['cancellation_reason' => 'nullable|string|max:1000']);
        $leaveRequest = $this->leave($request, $id);

        return $this->domain(fn () => app(CancelLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                cancellationReason: $validated['cancellation_reason'] ?? null,
            ));
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
        $visibleIds = WorkforceAccess::scopeIdsOrNull($request, 'leave');

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
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employeeId, 'leave'), 403); // ADR-780 : view-all, self or direct report

        return response()->json(
            LeaveBalanceReadModel::forEmployee($companyId, $employeeId, $request->query('year') ? (int) $request->query('year') : null)
        );
    }

    public function statistics(Request $request): JsonResponse
    {
        return response()->json(LeaveRequestReadModel::statistics(
            $request->attributes->get('company_id'),
            $request->query('year') ? (int) $request->query('year') : null,
            WorkforceAccess::scopeIdsOrNull($request, 'leave'), // ADR-781
        ));
    }
}
