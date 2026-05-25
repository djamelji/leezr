<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
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
            $leaves = LeaveRequestReadModel::forEmployee(
                companyId: $companyId,
                employeeId: (int) $employeeId,
                from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
                to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
                status: $request->query('status'),
                perPage: $perPage,
            );
        } else {
            $leaves = LeaveRequestReadModel::pendingForApproval($companyId, $perPage);
        }

        return response()->json($leaves);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(
            LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $request->attributes->get('company_id'))
                ->with(['leaveType', 'employee'])
                ->findOrFail($id)
        );
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
        ]);

        return response()->json(LeaveRequestReadModel::calendar(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
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
