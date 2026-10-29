<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\TimeEntryReadModel;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\ClockInUseCase;
use App\Modules\Workforce\UseCases\ClockOutUseCase;
use App\Modules\Workforce\UseCases\DeleteTimeEntryUseCase;
use App\Modules\Workforce\UseCases\EndBreakUseCase;
use App\Modules\Workforce\UseCases\StartBreakUseCase;
use App\Modules\Workforce\UseCases\UpdateTimeEntryUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $request->validate([
            'employee_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        // ADR-634 : voir les pointages d'un collaborateur exige « voir tout », soi-même, ou rapport direct.
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, (int) $request->query('employee_id'), 'time'),
            403,
            'Not authorized to view this employee\'s time entries.'
        );

        $entries = TimeEntryReadModel::forEmployee(
            companyId: $companyId,
            employeeId: (int) $request->query('employee_id'),
            from: Carbon::parse($request->query('from', now()->startOfWeek()->toDateString())),
            to: Carbon::parse($request->query('to', now()->endOfWeek()->toDateString())),
        );

        return response()->json($entries);
    }

    public function companyIndex(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:idle,working,on_break,completed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // ADR-634 : la vue entreprise des pointages est réservée aux capacités « voir tout » temps ;
        // ciblée sur un collaborateur, elle applique le scope (soi + rapports directs).
        $reqEmployeeId = $request->query('employee_id') ? (int) $request->query('employee_id') : null;
        if ($reqEmployeeId !== null) {
            abort_unless(
                WorkforceAccess::canViewEmployeeData($request, $reqEmployeeId, 'time'),
                403,
                'Not authorized to view this employee\'s time entries.'
            );
        } else {
            abort_unless(
                WorkforceAccess::hasViewAll($request, 'time'),
                403,
                'Not authorized to view company-wide time entries.'
            );
        }

        return response()->json(TimeEntryReadModel::forCompany(
            companyId: $companyId,
            from: $request->query('from'),
            to: $request->query('to'),
            employeeId: $reqEmployeeId,
            status: $request->query('status'),
            perPage: (int) $request->query('per_page', 15),
        ));
    }

    public function anomalies(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(TimeEntryReadModel::detectAnomalies(
            companyId: $companyId,
            from: $request->query('from'),
            to: $request->query('to'),
        ));
    }

    /** W-G5 — scope temps : vue globale si « voir tout » (timesheet_approve/time_manage) ; sinon soi + rapports. */
    private function timeScope(Request $request): ?array
    {
        return WorkforceAccess::hasViewAll($request, 'time')
            ? null
            : WorkforceAccess::visibleEmployeeIds($request, 'time');
    }

    /** W-G5 — synthèse opérationnelle du cockpit Pointage (jour borné, scopé). */
    public function cockpitSummary(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date']);

        return response()->json(\App\Modules\Workforce\ReadModels\TimeCockpitReadModel::summary(
            companyId: $request->attributes->get('company_id'),
            date: $request->filled('date') ? Carbon::parse($request->query('date')) : Carbon::today(),
            visibleIds: $this->timeScope($request),
        ));
    }

    /** W-G5 — tableau opérationnel prévu/réalisé réconcilié (jour borné, scopé + filtré). */
    public function cockpitBoard(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'department_id' => 'sometimes|integer',
            'employee_id' => 'sometimes|integer',
            'status' => 'sometimes|string',
        ]);

        return response()->json(\App\Modules\Workforce\ReadModels\TimeCockpitReadModel::board(
            companyId: $request->attributes->get('company_id'),
            date: $request->filled('date') ? Carbon::parse($request->query('date')) : Carbon::today(),
            visibleIds: $this->timeScope($request),
            filters: [
                'department_id' => $request->query('department_id'),
                'employee_id' => $request->query('employee_id'),
                'status' => $request->query('status'),
            ],
        ));
    }

    public function active(Request $request): JsonResponse
    {
        return response()->json(
            TimeEntryReadModel::activeForCompany($request->attributes->get('company_id'))
        );
    }

    public function clockIn(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'source' => 'nullable|string|in:manual,mobile,kiosk,import',
        ]);

        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($validated['employee_id']);

        try {
            $entry = app(ClockInUseCase::class)->execute(
                employee: $employee,
                source: $validated['source'] ?? 'manual',
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry, 201);
    }

    public function clockOut(Request $request, int $id): JsonResponse
    {
        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            $entry = app(ClockOutUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry);
    }

    public function startBreak(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:lunch,rest,personal',
        ]);

        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            $break = app(StartBreakUseCase::class)->execute(
                entry: $entry,
                type: $validated['type'] ?? 'rest',
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break, 201);
    }

    public function endBreak(Request $request, int $id): JsonResponse
    {
        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            $break = app(EndBreakUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date',
            'clock_in' => 'required|date',
            'clock_out' => 'required|date|after:clock_in',
        ]);

        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($validated['employee_id']);

        $entry = TimeEntry::create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'clock_in' => Carbon::parse($validated['clock_in']),
            'clock_out' => Carbon::parse($validated['clock_out']),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
        ]);

        $entry->computeTotals();
        $entry->update([
            'total_worked_minutes' => $entry->total_worked_minutes,
            'total_break_minutes' => $entry->total_break_minutes,
        ]);

        return response()->json($entry, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // Tenant d'abord (404 pour une ressource inexistante/d'une autre Company avant toute validation).
        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        // W-G5 : correction guidée → MOTIF OBLIGATOIRE (traçabilité). reason transmis au use case.
        $validated = $request->validate([
            'date' => 'nullable|date',
            'clock_in' => 'nullable|date',
            'clock_out' => 'nullable|date',
            'reason' => 'required|string|max:1000',
        ]);

        // W-G5 : scope manager — on ne corrige que les pointages de collaborateurs visibles.
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $entry->employee_id, 'time'),
            403,
            'Not authorized to correct this employee\'s time entry.'
        );

        try {
            $entry = app(UpdateTimeEntryUseCase::class)->execute($entry, $validated, $request->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            app(DeleteTimeEntryUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Time entry deleted']);
    }
}
