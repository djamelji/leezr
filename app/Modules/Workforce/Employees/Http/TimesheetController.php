<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\TimesheetPeriod;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\TimesheetAnomalyReadModel;
use App\Modules\Workforce\ReadModels\TimesheetLineReadModel;
use App\Modules\Workforce\ReadModels\TimesheetPeriodReadModel;
use App\Modules\Workforce\UseCases\ApproveTimesheetUseCase;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use App\Modules\Workforce\UseCases\LockTimesheetUseCase;
use App\Modules\Workforce\UseCases\RejectTimesheetUseCase;
use App\Modules\Workforce\UseCases\ReopenTimesheetUseCase;
use App\Modules\Workforce\UseCases\SubmitTimesheetUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $timesheets = TimesheetPeriodReadModel::forCompany(
            companyId: $companyId,
            periodStart: $request->input('period_start'),
            periodEnd: $request->input('period_end'),
            status: $request->input('status'),
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($timesheets);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('employee:id,first_name,last_name')
            ->findOrFail($id);

        $lines = TimesheetLineReadModel::forPeriod($period->id);
        $anomalies = TimesheetAnomalyReadModel::forPeriod($period->id);

        return response()->json([
            'period' => $period,
            'lines' => $lines,
            'anomalies' => $anomalies,
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        try {
            $period = app(GenerateTimesheetUseCase::class)->execute(
                companyId: $companyId,
                employeeId: $validated['employee_id'],
                periodStart: $validated['period_start'],
                periodEnd: $validated['period_end'],
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period, 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $period = app(SubmitTimesheetUseCase::class)->execute(
                period: $period,
                submittedBy: $request->user()->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'approval_note' => 'nullable|string|max:1000',
        ]);

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $period = app(ApproveTimesheetUseCase::class)->execute(
                period: $period,
                approvedBy: $request->user()->id,
                approvalNote: $validated['approval_note'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $period = app(RejectTimesheetUseCase::class)->execute(
                period: $period,
                rejectedBy: $request->user()->id,
                rejectionReason: $validated['rejection_reason'],
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $period = app(ReopenTimesheetUseCase::class)->execute(
                period: $period,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $period = app(LockTimesheetUseCase::class)->execute(
                period: $period,
                lockedBy: $request->user()->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($period);
    }

    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $stats = TimesheetPeriodReadModel::statistics(
            companyId: $companyId,
            periodStart: $validated['period_start'],
            periodEnd: $validated['period_end'],
        );

        return response()->json($stats);
    }
}
