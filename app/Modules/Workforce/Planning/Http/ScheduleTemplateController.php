<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\ScheduleTemplate;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\ScheduleTemplateReadModel;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\UpdateScheduleTemplateUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(
            $request->boolean('all')
                ? ScheduleTemplateReadModel::all($companyId)
                : ScheduleTemplateReadModel::forCompany($companyId)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_duration_minutes' => 'nullable|integer|min:0|max:480',
            'color' => 'nullable|string|max:7',
            'work_location_id' => 'nullable|integer',
        ]);

        try {
            $template = app(CreateScheduleTemplateUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                name: $validated['name'],
                startTime: $validated['start_time'],
                endTime: $validated['end_time'],
                breakDurationMinutes: $validated['break_duration_minutes'] ?? 0,
                color: $validated['color'] ?? null,
                workLocationId: $validated['work_location_id'] ?? null,
            );

            return response()->json($template, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = ScheduleTemplate::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'break_duration_minutes' => 'nullable|integer|min:0|max:480',
            'color' => 'nullable|string|max:7',
            'work_location_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        try {
            return response()->json(app(UpdateScheduleTemplateUseCase::class)->execute($template, $validated));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = ScheduleTemplate::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($template->shifts()->exists()) {
            return response()->json(['message' => 'Cannot delete a template with existing shifts.'], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
