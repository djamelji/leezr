<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\ScheduleTemplate;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\ScheduleTemplateReadModel;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
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
            'code' => 'nullable|string|max:30',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_duration_minutes' => 'nullable|integer|min:0|max:480',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:64',
            'activity_type' => 'nullable|string|max:60',
            'coverage_category_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'composition' => 'nullable|array', // M1 (ADR-724) — structure validée par le domaine ci-dessous
        ]);

        try {
            // M1 : composition validée/normalisée (bornes, chevauchements, priorités, ids stables) contre les horaires.
            $composition = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize(
                $validated['composition'] ?? null,
                $validated['start_time'],
                $validated['end_time'],
            );

            $template = app(CreateScheduleTemplateUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                name: $validated['name'],
                startTime: $validated['start_time'],
                endTime: $validated['end_time'],
                breakDurationMinutes: $validated['break_duration_minutes'] ?? 0,
                color: $validated['color'] ?? null,
                workLocationId: $validated['work_location_id'] ?? null,
                code: $validated['code'] ?? null,
                icon: $validated['icon'] ?? null,
                activityType: $validated['activity_type'] ?? null,
                departmentId: $validated['department_id'] ?? null,
                coverageCategoryId: $validated['coverage_category_id'] ?? null,
                composition: $composition,
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
            'code' => 'nullable|string|max:30',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'break_duration_minutes' => 'nullable|integer|min:0|max:480',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:64',
            'activity_type' => 'nullable|string|max:60',
            'coverage_category_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'composition' => 'nullable|array', // M1 (ADR-724)
        ]);

        try {
            // M1 : si la composition est fournie, la valider contre les horaires EFFECTIFS (nouveaux ou actuels)
            // et la fusionner dans metadata sans écraser les autres clés. null explicite ⇒ composition retirée.
            if ($request->has('composition')) {
                $effectiveStart = $validated['start_time'] ?? substr((string) $template->start_time, 0, 5);
                $effectiveEnd = $validated['end_time'] ?? substr((string) $template->end_time, 0, 5);
                $composition = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize(
                    $validated['composition'] ?? null, $effectiveStart, $effectiveEnd,
                );
                $metadata = is_array($template->metadata) ? $template->metadata : [];
                unset($metadata['composition']);
                if ($composition !== null) {
                    $metadata['composition'] = $composition;
                }
                $validated['metadata'] = $metadata ?: null;
            }
            unset($validated['composition']);

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
