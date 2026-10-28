<?php

namespace App\Modules\Platform\Organization\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Organization\OrganizationPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-636 (W-E) — administration Platform (minimum) des presets organisationnels : liste + cycle de vie
 * (draft → published → archived). Gardé par `manage_jobdomains` (aucun accès implicite). L'éditeur
 * visuel complet de structure est un COMPLÉMENT déclaré (non livré dans W-E).
 */
class PlatformOrganizationPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = OrganizationPreset::query()->orderBy('jobdomain_key')->orderBy('org_profile_key')->orderByDesc('version');
        if ($request->filled('jobdomain_key')) {
            $q->where('jobdomain_key', $request->query('jobdomain_key'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return response()->json($q->paginate((int) $request->query('per_page', 25)));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(OrganizationPreset::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePreset($request, true);
        $data['created_by'] = $request->user()?->id;
        $data['status'] = 'draft';

        return response()->json(OrganizationPreset::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        if ($preset->status === 'published') {
            return response()->json(['message' => 'A published preset is immutable; create a new version.'], 422);
        }
        $preset->update($this->validatePreset($request, false));

        return response()->json($preset);
    }

    public function publish(int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        $preset->update(['status' => 'published', 'published_at' => now()]);

        return response()->json($preset);
    }

    public function archive(int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        $preset->update(['status' => 'archived']);

        return response()->json($preset);
    }

    private function validatePreset(Request $request, bool $creating): array
    {
        return $request->validate([
            'key' => ($creating ? 'required' : 'sometimes').'|string|max:80|unique:organization_presets,key'.($creating ? '' : ','.$request->route('id')),
            'label' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'jobdomain_key' => ($creating ? 'required' : 'sometimes').'|string|max:60',
            'market_key' => 'nullable|string|max:10',
            'org_profile_key' => 'nullable|string|max:60',
            'version' => 'nullable|integer|min:1',
            'structure' => ($creating ? 'required' : 'sometimes').'|array',
            'structure.departments' => 'sometimes|array',
            'structure.job_roles' => 'sometimes|array',
            'description' => 'nullable|string',
        ]);
    }
}
