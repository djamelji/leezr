<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\ReadModels\EmployeeReadModel;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
use App\Modules\Workforce\ReadModels\TimeEntryReadModel;
use App\Modules\Workforce\UseCases\ClockInUseCase;
use App\Modules\Workforce\UseCases\ClockOutUseCase;
use App\Modules\Workforce\UseCases\EndBreakUseCase;
use App\Modules\Workforce\UseCases\RequestLeaveUseCase;
use App\Modules\Workforce\UseCases\StartBreakUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee self-service controller.
 *
 * Resolves the authenticated user's employee record automatically,
 * eliminating the security flaw where employee_id was passed by the client.
 */
class EmployeeSelfController
{
    private function resolveEmployee(Request $request): Employee
    {
        return Employee::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->where('user_id', $request->user()->id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->firstOrFail();
    }

    /** GET /workforce/me */
    public function profile(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        $todayEntry = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('date', today()->toDateString())
            ->with('breaks')
            ->latest('clock_in')
            ->first();

        return response()->json([
            'employee' => EmployeeReadModel::detail($companyId, $employee->id),
            'today_clock' => $todayEntry ? [
                'id' => $todayEntry->id,
                'status' => $todayEntry->status,
                'clock_in' => $todayEntry->clock_in?->toIso8601String(),
                'clock_out' => $todayEntry->clock_out?->toIso8601String(),
                'total_worked_minutes' => $todayEntry->total_worked_minutes,
                'total_break_minutes' => $todayEntry->total_break_minutes,
                'breaks' => $todayEntry->breaks->map(fn ($b) => [
                    'id' => $b->id,
                    'type' => $b->type,
                    'start_at' => $b->start_at?->toIso8601String(),
                    'end_at' => $b->end_at?->toIso8601String(),
                    'duration_minutes' => $b->duration_minutes,
                ]),
            ] : null,
            'leave_balances' => LeaveBalanceReadModel::forEmployee($companyId, $employee->id),
        ]);
    }

    /** GET /workforce/me/today */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');
        $today = Carbon::today();

        $entries = TimeEntryReadModel::forEmployee($companyId, $employee->id, $today, $today);

        return response()->json([
            'entries' => $entries,
            'total_worked_minutes' => $entries->sum('total_worked_minutes'),
            'total_break_minutes' => $entries->sum('total_break_minutes'),
        ]);
    }

    /** POST /workforce/me/clock-in */
    public function clockIn(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $validated = $request->validate(['source' => 'nullable|string|in:manual,mobile,kiosk']);

        try {
            $entry = app(ClockInUseCase::class)->execute($employee, $validated['source'] ?? 'manual');
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry, 201);
    }

    /** POST /workforce/me/clock-out */
    public function clockOut(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        $entry = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->active()
            ->latest('clock_in')
            ->firstOrFail();

        try {
            $entry = app(ClockOutUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry);
    }

    /** POST /workforce/me/break/start */
    public function startBreak(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate(['type' => 'nullable|string|in:lunch,rest,personal']);

        $entry = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', TimeEntry::STATUS_WORKING)
            ->latest('clock_in')
            ->firstOrFail();

        try {
            $break = app(StartBreakUseCase::class)->execute($entry, $validated['type'] ?? 'rest');
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break, 201);
    }

    /** POST /workforce/me/break/end */
    public function endBreak(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        $entry = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', TimeEntry::STATUS_ON_BREAK)
            ->latest('clock_in')
            ->firstOrFail();

        try {
            $break = app(EndBreakUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break);
    }

    /** GET /workforce/me/leaves */
    public function leaves(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        return response()->json(
            LeaveRequestReadModel::forEmployee(
                companyId: $companyId,
                employeeId: $employee->id,
                status: $request->query('status'),
                perPage: $request->integer('perPage', 15),
            )
        );
    }

    /** POST /workforce/me/leaves */
    public function createLeave(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'leave_type_id' => 'required|integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'days_count_hundredths' => 'required|integer|min:50',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $leaveRequest = app(RequestLeaveUseCase::class)->execute(
                employee: $employee,
                leaveTypeId: $validated['leave_type_id'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                daysCountHundredths: $validated['days_count_hundredths'],
                reason: $validated['reason'] ?? null,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($leaveRequest, 201);
    }

    /** GET /workforce/me/leave-balances */
    public function leaveBalances(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        return response()->json([
            'balances' => LeaveBalanceReadModel::forEmployee($companyId, $employee->id),
        ]);
    }

    /** GET /workforce/me/documents */
    public function documents(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        return response()->json([
            'documents' => GeneratedDocumentReadModel::forEmployee($employee->id, $companyId),
        ]);
    }
}
