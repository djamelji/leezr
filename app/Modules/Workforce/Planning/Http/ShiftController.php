<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use App\Modules\Workforce\UseCases\BulkCreateShiftsUseCase;
use App\Modules\Workforce\UseCases\CancelShiftUseCase;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use App\Modules\Workforce\UseCases\UpdateShiftUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $request->validate([
            'employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:draft,published,completed,cancelled',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(ShiftReadModel::forEmployee(
            companyId: $companyId,
            employeeId: $request->query('employee_id') ? (int) $request->query('employee_id') : 0,
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
            status: $request->query('status'),
            perPage: (int) $request->query('per_page', 15),
        ));
    }

    public function week(Request $request): JsonResponse
    {
        $request->validate(['date' => 'required|date']);

        return response()->json(ShiftReadModel::forWeek(
            companyId: $request->attributes->get('company_id'),
            weekStart: Carbon::parse($request->query('date'))->startOfWeek(),
        ));
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(ShiftReadModel::calendar(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
        ));
    }

    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return response()->json(ShiftReadModel::statistics(
            companyId: $request->attributes->get('company_id'),
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'timezone' => 'required|string|max:50',
            'schedule_template_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            return response()->json(app(CreateShiftUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                employeeId: $validated['employee_id'],
                date: $validated['date'],
                startAt: $validated['start_at'],
                endAt: $validated['end_at'],
                timezone: $validated['timezone'],
                scheduleTemplateId: $validated['schedule_template_id'] ?? null,
                workLocationId: $validated['work_location_id'] ?? null,
                notes: $validated['notes'] ?? null,
            ), 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate([
            'date' => 'nullable|date',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'timezone' => 'nullable|string|max:50',
            'schedule_template_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            return response()->json(app(UpdateShiftUseCase::class)->execute($shift, $validated));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($shift->status !== Shift::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft shifts can be deleted.'], 422);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate(['shift_ids' => 'required|array|min:1', 'shift_ids.*' => 'integer']);

        try {
            $shifts = app(PublishShiftsUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                shiftIds: $validated['shift_ids'],
            );

            return response()->json($shifts);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            return response()->json(app(CancelShiftUseCase::class)->execute($shift, $validated['reason'] ?? null));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bulkCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_template_id' => 'required|integer',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'work_location_id' => 'nullable|integer',
        ]);

        try {
            return response()->json(app(BulkCreateShiftsUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                scheduleTemplateId: $validated['schedule_template_id'],
                employeeIds: $validated['employee_ids'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                workLocationId: $validated['work_location_id'] ?? null,
            ), 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
