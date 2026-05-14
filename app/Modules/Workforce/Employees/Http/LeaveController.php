<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
use App\Modules\Workforce\ReadModels\LeaveTypeReadModel;
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
            $leaves = LeaveRequestReadModel::pendingForApproval(
                companyId: $companyId,
                perPage: $perPage,
            );
        }

        return response()->json($leaves);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['leaveType', 'employee'])
            ->findOrFail($id);

        return response()->json($leaveRequest);
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
            $leaveRequest = app(RequestLeaveUseCase::class)->execute(
                employee: $employee,
                leaveTypeId: $validated['leave_type_id'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                daysCountHundredths: $validated['days_count_hundredths'],
                reason: $validated['reason'] ?? null,
            );

            return response()->json($leaveRequest, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $leaveRequest = app(ApproveLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            );

            return response()->json($leaveRequest);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $leaveRequest = app(RejectLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                reviewerId: $request->user()->id,
                reviewNote: $validated['review_note'] ?? null,
            );

            return response()->json($leaveRequest);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $leaveRequest = app(CancelLeaveRequestUseCase::class)->execute(
                request: $leaveRequest,
                cancellationReason: $validated['cancellation_reason'] ?? null,
            );

            return response()->json($leaveRequest);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function types(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $types = LeaveTypeReadModel::forCompany($companyId);

        return response()->json($types);
    }

    public function balances(Request $request, int $employeeId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $year = $request->query('year') ? (int) $request->query('year') : null;

        // Ensure employee belongs to this company
        Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($employeeId);

        $balances = LeaveBalanceReadModel::forEmployee($companyId, $employeeId, $year);

        return response()->json($balances);
    }

    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $year = $request->query('year') ? (int) $request->query('year') : null;

        $stats = LeaveRequestReadModel::statistics($companyId, $year);

        return response()->json($stats);
    }
}
