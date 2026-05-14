<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\TimeEntryReadModel;
use App\Modules\Workforce\UseCases\ClockInUseCase;
use App\Modules\Workforce\UseCases\ClockOutUseCase;
use App\Modules\Workforce\UseCases\EndBreakUseCase;
use App\Modules\Workforce\UseCases\StartBreakUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeEntryController extends Controller
{
    /**
     * List time entries for an employee within a date range.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $request->validate([
            'employee_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = Carbon::parse($request->query('from', now()->startOfWeek()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->endOfWeek()->toDateString()));

        $entries = TimeEntryReadModel::forEmployee(
            companyId: $companyId,
            employeeId: (int) $request->query('employee_id'),
            from: $from,
            to: $to,
        );

        return response()->json($entries);
    }

    /**
     * List all currently active time entries across the company.
     */
    public function active(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $entries = TimeEntryReadModel::activeForCompany($companyId);

        return response()->json($entries);
    }

    /**
     * Clock in an employee.
     */
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

    /**
     * Clock out a time entry.
     */
    public function clockOut(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $entry = app(ClockOutUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry);
    }

    /**
     * Start a break on an active time entry.
     */
    public function startBreak(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'type' => 'nullable|string|in:lunch,rest,personal',
        ]);

        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
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

    /**
     * End the current break on a time entry.
     */
    public function endBreak(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $break = app(EndBreakUseCase::class)->execute($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($break);
    }

    /**
     * Manually create a completed time entry (e.g. back-fill or correction).
     */
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
}
