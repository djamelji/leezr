<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\ReadModels\EmployeeReadModel;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\ReadModels\LeaveBalanceReadModel;
use App\Modules\Workforce\ReadModels\LeaveRequestReadModel;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
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

        return response()->json([
            // ADR-779 : today's entry + an entry left open on a previous day (TimeEntryReadModel).
            ...TimeEntryReadModel::selfClockState($companyId, $employee->id),
            'employee' => EmployeeReadModel::detail($companyId, $employee->id, withAccount: true), // son propre compte
            'leave_balances' => LeaveBalanceReadModel::forEmployee($companyId, $employee->id),
            // Resolved break rules (WorkforceRuleResolver §2): the UI countdown/policy come from
            // the server + rules engine, never from Vue constants. Flattened for the client.
            'break_rules' => $this->breakRulesPayload($companyId),
        ]);
    }

    /** Resolved break rules, flattened by the rules engine (never Vue constants). */
    private function breakRulesPayload(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);

        return $company ? app(WorkforceRuleResolver::class)->breakRulesForClient($company) : [];
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

        $entry = TimeEntryReadModel::latestActiveOrFail($companyId, $employee->id);

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

        $entry = TimeEntryReadModel::latestActiveOrFail($companyId, $employee->id, TimeEntry::STATUS_WORKING);

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

        $entry = TimeEntryReadModel::latestActiveOrFail($companyId, $employee->id, TimeEntry::STATUS_ON_BREAK);

        // Early-resume policy (server-enforced, driven by the rules engine — never a Vue constant).
        $rules = $this->breakRulesPayload($companyId);
        $minMinutes = $rules['min_duration_minutes'] ?? null;
        $policy = $rules['early_resume_policy'] ?? 'warn';
        $openBreak = $entry->breaks()->whereNull('end_at')->latest('start_at')->first();
        $elapsedMin = $openBreak?->start_at ? $openBreak->start_at->diffInMinutes(now()) : null;

        if ($minMinutes !== null && $elapsedMin !== null && $elapsedMin < $minMinutes) {
            // 'block' always refuses; 'approve' refuses for self-service (needs a manager with
            // workforce.time_manage). 'warn'/'allow' proceed (the UI shows the warning).
            $canApprove = $request->user()?->hasCompanyPermission($request->attributes->get('company'), 'workforce.time_manage') ?? false;
            if ($policy === 'block' || ($policy === 'approve' && ! $canApprove)) {
                return response()->json([
                    'message' => "Minimum break not reached ({$elapsedMin}/{$minMinutes} min).",
                    'code' => $policy === 'approve' ? 'break_needs_approval' : 'break_min_not_reached',
                    'elapsed_minutes' => $elapsedMin,
                    'min_minutes' => $minMinutes,
                    'policy' => $policy,
                ], 422);
            }
        }

        try {
            $break = app(EndBreakUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break);
    }

    /**
     * GET /workforce/me/shifts — créneaux de planning du salarié sur une fenêtre glissante.
     *
     * W-HR-UX-4 §6 : le salarié doit pouvoir CONSULTER son propre planning sans menu obscur.
     * Ne renvoie QUE les créneaux PUBLIÉS (jamais les brouillons RH) : l'employé ne voit pas un
     * planning tant que la RH ne l'a pas publié. Lecture seule, tenant-scoped sur l'employé résolu.
     */
    public function shifts(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        $companyId = $request->attributes->get('company_id');

        $from = Carbon::today();
        $to = (clone $from)->addDays((int) $request->integer('days', 14));

        return response()->json([
            'shifts' => ShiftReadModel::publishedForEmployee($companyId, $employee->id, $from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
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

    /**
     * POST /workforce/me/leaves/deduction-preview — ADR-779 (UX4).
     *
     * The person sees the duration the EXISTING engine (LeaveDeductionCalculator) proposes for her own
     * period, before sending. Same suggestion as the manager form ; read-only ; the employee is always
     * the authenticated person's active record (never a parameter).
     */
    public function leaveDeductionPreview(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $contract = $employee->currentContract;
        if (! $contract) {
            return response()->json(['message' => 'Employee has no active contract.'], 422);
        }

        return response()->json(app(\App\Modules\Workforce\Services\LeaveDeductionCalculator::class)->suggest(
            $contract,
            $validated['date_from'],
            $validated['date_to'],
        ));
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
