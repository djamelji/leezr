<?php

namespace App\Modules\Platform\Organization\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Modules\Workforce\Organization\OrganizationPresetService;
use App\Modules\Workforce\Organization\OrganizationPresetStructureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-636/638 (W-E/W-E2) — administration Platform des presets organisationnels : catalogue, edition de
 * brouillon, validation serveur de structure, versionnement par famille (publie immuable -> nouvelle
 * version), publication (une seule publiee par famille), archivage (references conservees), simulation
 * read-only sur une Company. Garde par manage_jobdomains ; toutes les mutations sont auditees.
 */
class PlatformOrganizationPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = OrganizationPreset::query()->orderBy('family_key')->orderByDesc('version');
        foreach (['jobdomain_key', 'market_key', 'org_profile_key', 'status'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->where(fn ($w) => $w->where('key', 'LIKE', "%{$s}%")->orWhere('label', 'LIKE', "%{$s}%"));
        }

        return response()->json($q->paginate((int) $request->query('per_page', 25)));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(OrganizationPreset::findOrFail($id));
    }

    /** Référentiels pour les sélecteurs de l'éditeur (jobdomains/markets réels + profils par jobdomain). */
    public function meta(): JsonResponse
    {
        $jobdomains = \App\Core\Jobdomains\Jobdomain::query()->get(['key', 'label'])
            ->map(fn ($j) => ['key' => $j->key, 'label' => $j->label])->all();
        $profilesByJobdomain = [];
        foreach ($jobdomains as $j) {
            $profilesByJobdomain[$j['key']] = \App\Modules\Workforce\Organization\OrgProfileRegistry::forJobdomain($j['key']);
        }

        return response()->json([
            'jobdomains' => $jobdomains,
            'markets' => \App\Core\Markets\Market::query()->where('is_active', true)->get(['key', 'name'])
                ->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->all(),
            'profiles_by_jobdomain' => $profilesByJobdomain,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePreset($request, true);
        $errors = OrganizationPresetStructureValidator::validate($data);
        if ($errors) {
            return response()->json(['message' => 'Invalid structure', 'errors' => $errors], 422);
        }
        $data['family_key'] = $data['family_key'] ?? $data['key'];
        $data['version'] = $data['version'] ?? 1;
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;
        $data['status'] = 'draft';

        $preset = OrganizationPreset::create($data);
        $this->audit('organization_preset.created', $preset);

        return response()->json($preset, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        if ($preset->status === 'published') {
            return response()->json(['message' => 'A published preset is immutable. Duplicate it to a new version to edit.'], 422);
        }
        $data = $this->validatePreset($request, false);
        $merged = array_merge($preset->only(['jobdomain_key', 'market_key', 'org_profile_key', 'structure']), $data);
        $errors = OrganizationPresetStructureValidator::validate($merged);
        if ($errors) {
            return response()->json(['message' => 'Invalid structure', 'errors' => $errors], 422);
        }
        $data['updated_by'] = $request->user()?->id;
        $preset->update($data);
        $this->audit('organization_preset.updated', $preset);

        return response()->json($preset);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        $errors = OrganizationPresetStructureValidator::validate($preset->toArray());
        if ($errors) {
            return response()->json(['message' => 'Cannot publish an invalid structure', 'errors' => $errors], 422);
        }
        OrganizationPreset::where('family_key', $preset->family_key)
            ->where('id', '!=', $preset->id)->where('status', 'published')
            ->update(['status' => 'archived']);
        $preset->update(['status' => 'published', 'published_at' => now(), 'updated_by' => $request->user()?->id]);
        $this->audit('organization_preset.published', $preset, 'warning');

        return response()->json($preset);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        $preset->update(['status' => 'archived', 'updated_by' => $request->user()?->id]);
        $this->audit('organization_preset.archived', $preset);

        return response()->json($preset);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $src = OrganizationPreset::findOrFail($id);
        $nextVersion = (int) OrganizationPreset::where('family_key', $src->family_key)->max('version') + 1;
        $draft = OrganizationPreset::create([
            'key' => $src->family_key.'_v'.$nextVersion,
            'family_key' => $src->family_key,
            'label' => $src->label,
            'jobdomain_key' => $src->jobdomain_key,
            'market_key' => $src->market_key,
            'org_profile_key' => $src->org_profile_key,
            'version' => $nextVersion,
            'status' => 'draft',
            'structure' => $src->structure,
            'description' => $src->description,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
        $this->audit('organization_preset.duplicated', $draft, 'info', ['from' => $src->key]);

        return response()->json($draft, 201);
    }

    public function simulate(Request $request, int $id): JsonResponse
    {
        $preset = OrganizationPreset::findOrFail($id);
        $company = Company::find((int) $request->query('company_id'));
        if (! $company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        return response()->json(OrganizationPresetService::diffAgainst($company, $preset));
    }

    private function audit(string $action, OrganizationPreset $preset, string $severity = 'info', array $meta = []): void
    {
        app(AuditLogger::class)->logPlatform($action, 'organization_preset', (string) $preset->id, [
            'severity' => $severity,
            'metadata' => array_merge(['key' => $preset->key, 'family_key' => $preset->family_key, 'version' => $preset->version, 'status' => $preset->status], $meta),
        ]);
    }

    private function validatePreset(Request $request, bool $creating): array
    {
        return $request->validate([
            'key' => ($creating ? 'required' : 'sometimes').'|string|max:80|unique:organization_presets,key'.($creating ? '' : ','.$request->route('id')),
            'family_key' => 'sometimes|nullable|string|max:80',
            'label' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'jobdomain_key' => ($creating ? 'required' : 'sometimes').'|string|max:60',
            'market_key' => 'sometimes|nullable|string|max:10',
            'org_profile_key' => 'sometimes|nullable|string|max:60',
            'version' => 'sometimes|nullable|integer|min:1',
            'structure' => ($creating ? 'required' : 'sometimes').'|array',
            'structure.departments' => 'sometimes|array',
            'structure.job_roles' => 'sometimes|array',
            'description' => 'sometimes|nullable|string',
        ]);
    }
}
