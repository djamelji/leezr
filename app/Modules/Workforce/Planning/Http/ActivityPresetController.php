<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\ActivityPreset;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\WorkLocation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD du catalogue de TÂCHES TYPES (ActivityPreset) — Refonte Smart Planning M2 (ADR-725).
 *
 * Company-scoped systématique (withoutGlobalScopes + where company_id). Lecture = planning_view,
 * écriture = planning_manage (le preset relève du même domaine template/composition). Références souples
 * (coverage_category_id / work_location_id) validées comme appartenant à la Company. La désactivation
 * (enabled=false) est réversible et ne casse aucune composition (copie par valeur).
 */
class ActivityPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            ActivityPreset::withoutGlobalScopes()
                ->where('company_id', $request->attributes->get('company_id'))
                ->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $companyId = $request->attributes->get('company_id');

        if (ActivityPreset::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $data['name'])->exists()) {
            return response()->json(['message' => "An activity preset named '{$data['name']}' already exists."], 422);
        }
        if ($err = $this->refError($companyId, $data)) {
            return response()->json(['message' => $err], 422);
        }

        $preset = ActivityPreset::create(array_merge($data, ['company_id' => $companyId]));
        app(AuditLogger::class)->logCompany($companyId, 'activity_preset.created', 'activity_preset', (string) $preset->id, ['metadata' => ['category' => 'workforce.planning', 'name' => $preset->name]]);

        return response()->json($preset, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $preset = ActivityPreset::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        $data = $this->validatePayload($request, false);

        if ($err = $this->refError($preset->company_id, $data)) {
            return response()->json(['message' => $err], 422);
        }
        if (isset($data['name']) && $data['name'] !== $preset->name
            && ActivityPreset::withoutGlobalScopes()->where('company_id', $preset->company_id)->where('name', $data['name'])->where('id', '!=', $preset->id)->exists()) {
            return response()->json(['message' => 'Name already used.'], 422);
        }

        $preset->fill($data)->save();
        app(AuditLogger::class)->logCompany($preset->company_id, 'activity_preset.updated', 'activity_preset', (string) $preset->id, ['metadata' => ['category' => 'workforce.planning']]);

        return response()->json($preset);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $preset = ActivityPreset::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        // Suppression SÛRE : aucune composition/ScheduledActivity n'en dépend (copie par valeur, pas de FK).
        $preset->delete();

        return response()->json(['message' => 'Activity preset deleted.']);
    }

    private function refError(int $companyId, array $data): ?string
    {
        if (! empty($data['coverage_category_id'])
            && ! CoverageCategory::withoutGlobalScopes()->where('id', $data['coverage_category_id'])->where('company_id', $companyId)->exists()) {
            return 'Coverage category does not belong to this company.';
        }
        if (! empty($data['work_location_id'])
            && ! WorkLocation::withoutGlobalScopes()->where('id', $data['work_location_id'])->where('company_id', $companyId)->exists()) {
            return 'Work location does not belong to this company.';
        }

        return null;
    }

    private function validatePayload(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'nullable').'|string|max:120',
            'code' => 'nullable|string|max:40',
            'activity_type' => 'nullable|string|max:60',
            'coverage_category_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'default_duration_minutes' => 'nullable|integer|min:1|max:1440',
            'priority' => 'nullable|in:'.implode(',', ActivityPreset::PRIORITIES),
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
            'non_exclusive' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
    }
}
