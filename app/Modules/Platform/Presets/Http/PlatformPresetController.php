<?php

namespace App\Modules\Platform\Presets\Http;

use App\Core\Models\Company;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-793 (L3) — Platform API of versioned presets (permission manage_jobdomains, like the organisation
 * presets). Drafts / publication / archive / new version / diff for RBAC, Requirements and Planning ;
 * the Organization family keeps its dedicated editor and is reachable here for diff, preview and apply.
 * Company application is EXPLICIT (never automatic, never on company creation in L3).
 */
class PlatformPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['kind' => 'sometimes|in:catalog,rbac,requirements,planning']);

        return response()->json(['presets' => Preset::query()->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('family_key')->orderByDesc('version')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:catalog,rbac,requirements,planning', 'family_key' => ['required', 'regex:/^[a-z0-9_]{2,80}$/'],
            'label' => 'required|string|max:255', 'description' => 'nullable|string|max:2000', 'content' => 'required|array',
            'jobdomain_key' => 'nullable|exists:jobdomains,key', 'market_key' => 'nullable|exists:markets,key', 'requires_modules' => 'nullable|array',
        ]);

        return response()->json(PresetService::createDraft($data['kind'], collect($data)->except('kind')->all(), $request->user()?->id), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['label' => 'sometimes|string|max:255', 'description' => 'nullable|string|max:2000', 'content' => 'sometimes|array', 'requires_modules' => 'nullable|array']);

        return response()->json(PresetService::updateDraft(Preset::findOrFail($id), $data, $request->user()?->id));
    }

    public function publish(Request $request, string $kind, int $id): JsonResponse
    {
        $preset = PresetService::find($kind, $id);
        // ADR-803 — a family followed by domains (unpinned links) changes for them : explicit `mode=shared`
        // only ; otherwise publish from the domain page (variant possible there).
        $followers = \App\Core\Presets\JobdomainPresetLink::where('kind', $kind)->where('family_key', $preset->family_key)->whereNull('pinned_version')->pluck('jobdomain_key')->unique()->values()->all();
        if ($followers && $request->input('mode') !== 'shared') {
            return response()->json(['message' => 'This preset is followed by domains.', 'code' => 'shared_preset', 'shared_with' => $followers], 409);
        }
        PresetService::publish($preset, $request->user()?->id);

        return response()->json($preset->fresh());
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $preset = Preset::findOrFail($id);
        $preset->archiveVersion($request->user()?->id);

        return response()->json($preset);
    }

    public function newVersion(Request $request, int $id): JsonResponse
    {
        return response()->json(Preset::findOrFail($id)->nextVersion($request->user()?->id), 201);
    }

    public function diff(string $kind, int $from, int $to): JsonResponse
    {
        return response()->json(PresetService::diff(PresetService::find($kind, $from), PresetService::find($kind, $to)));
    }

    public function preview(string $kind, int $id, int $companyId): JsonResponse
    {
        return response()->json(PresetService::preview(Company::withoutGlobalScopes()->findOrFail($companyId), PresetService::find($kind, $id)));
    }

    public function apply(Request $request, string $kind, int $id, int $companyId): JsonResponse
    {
        $data = $request->validate(['selected' => 'nullable|array', 'selected.*' => 'string|max:200']);

        return response()->json(PresetService::apply(Company::withoutGlobalScopes()->findOrFail($companyId), PresetService::find($kind, $id), $data['selected'] ?? null, 'platform', $request->user()?->id));
    }

    public function recommendations(Request $request, string $jobdomain): JsonResponse
    {
        return response()->json(['recommendations' => PresetService::recommendationsFor($jobdomain, $request->query('market'))]);
    }

    public function setRecommendations(Request $request, string $jobdomain): JsonResponse
    {
        $data = $request->validate(['links' => 'present|array', 'links.*.kind' => 'required|in:catalog,rbac,organization,requirements,planning',
            'links.*.family_key' => 'required|string|max:80', 'links.*.market_key' => 'nullable|exists:markets,key',
            'links.*.pinned_version' => 'nullable|integer|min:1']);
        abort_unless(\App\Core\Jobdomains\Jobdomain::where('key', $jobdomain)->exists(), 404);
        PresetService::setRecommendations($jobdomain, $data['links']);

        return response()->json(['recommendations' => PresetService::recommendationsFor($jobdomain)]);
    }
}
