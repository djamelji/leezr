<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\TimeEntryReadModel;
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

        return response()->json(TimeEntryReadModel::forCompany(
            companyId: $companyId,
            from: $request->query('from'),
            to: $request->query('to'),
            employeeId: $request->query('employee_id') ? (int) $request->query('employee_id') : null,
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
        $validated = $request->validate([
            'date' => 'nullable|date',
            'clock_in' => 'nullable|date',
            'clock_out' => 'nullable|date',
        ]);

        $entry = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        try {
            $entry = app(UpdateTimeEntryUseCase::class)->execute($entry, $validated);
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
