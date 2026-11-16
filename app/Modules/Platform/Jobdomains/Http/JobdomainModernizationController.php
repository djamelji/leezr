<?php

namespace App\Modules\Platform\Jobdomains\Http;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\Modernization\CompanyJobdomainCompatibility;
use App\Core\Jobdomains\Modernization\JobdomainLegacyFootprint;
use App\Core\Jobdomains\Modernization\LegacyPresetDraftFactory;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\PresetService;
use App\Core\Presets\JobdomainPresetLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-797 (L7) — JobDomain as a business context and recommendation orchestrator (manage_jobdomains) :
 * profile (identity, recommended modules and presets, compatibility diagnostic), legacy → draft preset,
 * prepared versioned initialisation (read-only plan + parity), company compatibility report (read-only),
 * preset detail. Nothing here applies a preset to a company or reconciles anything.
 */
class JobdomainModernizationController extends Controller
{
    public function profile(int $id): JsonResponse
    {
        $jd = Jobdomain::findOrFail($id);

        return response()->json([
            'identity' => $jd->only(['id', 'key', 'label', 'description', 'sector', 'terminology', 'is_active', 'allow_custom_fields']),
            'markets' => Market::orderBy('key')->get(['key', 'name', 'is_active'])->map(fn ($m) => $m->only(['key', 'name', 'is_active'])
                + ['has_override' => $jd->overlays()->where('market_key', $m->key)->exists()])->values(),
            'modules' => VersionedCompanyInitializer::modules((array) $jd->default_modules),
            'available_modules' => collect(ModuleRegistry::forScope('company'))->map(fn ($m) => ['key' => $m->key, 'name' => $m->name, 'type' => $m->type,
                'min_plan' => $m->minPlan, 'requires' => $m->requires, 'core' => str_starts_with($m->key, 'core.')])->values(),
            'recommendations' => PresetService::recommendationsFor($jd->key),
            'company_list' => Company::where('jobdomain_key', $jd->key)->orderBy('name')->limit(500)->get(['id', 'name'])->map->only(['id', 'name'])->values(),
            'compatibility' => JobdomainLegacyFootprint::for($jd),
            'legacy' => ['roles' => (array) $jd->default_roles, 'documents' => (array) $jd->default_documents, 'fields' => (array) $jd->default_fields,
                'planning' => array_map(fn ($v) => is_array($v) ? count($v) : $v, (array) $jd->workforce_presets), 'editable' => (bool) config('jobdomains.legacy_editing')],
        ]);
    }

    public function legacyDraft(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:rbac,requirements,planning']);
        $result = LegacyPresetDraftFactory::draft(Jobdomain::findOrFail($id), $data['kind'], $request->user()?->id);

        return response()->json(['preset' => $result['preset'], 'skipped' => $result['skipped'], 'not_carried' => $result['not_carried']], 201);
    }

    public function initializationPlan(Request $request, int $id): JsonResponse
    {
        $request->validate(['market' => 'nullable|exists:markets,key']);
        $jd = Jobdomain::findOrFail($id);

        return response()->json(['plan' => VersionedCompanyInitializer::plan($jd->key, $request->query('market')),
            'parity' => VersionedCompanyInitializer::parity($jd->key, $request->query('market'))]);
    }

    public function companyCompatibility(int $companyId): JsonResponse
    {
        return response()->json(CompanyJobdomainCompatibility::report(Company::query()->where('id', $companyId)->firstOrFail()));
    }

    /** One preset version : its items (labels), the versions of its family, the JobDomains recommending it. */
    public function presetDetail(string $kind, int $id): JsonResponse
    {
        $preset = PresetService::find($kind, $id);
        $items = PresetService::adapter($kind)->items(PresetService::content($preset));

        return response()->json([
            'preset' => $preset,
            'items' => array_map(fn ($k, $i) => ['key' => $k, 'label' => $i['name'] ?? $i['code'] ?? $i['title'] ?? $k, 'detail' => self::itemDetail($kind, $i)], array_keys($items), $items),
            'versions' => PresetService::familyVersions($kind, $preset->family_key)->map(fn ($v) => ['id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'published_at' => $v->published_at])->values(),
            'recommended_by' => JobdomainPresetLink::where('kind', $kind)->where('family_key', $preset->family_key)->get(['jobdomain_key', 'market_key', 'pinned_version']),
        ]);
    }

    private static function itemDetail(string $kind, array $i): ?string
    {
        return match ($kind) {
            'rbac' => count($i['permissions'] ?? []).' permissions'.(($i['is_administrative'] ?? false) ? ' · administrative' : ''),
            'requirements' => ($i['level'] ?? '').(($i['job_role_keys'] ?? []) ? ' · '.implode(', ', $i['job_role_keys']) : ''),
            default => null,
        };
    }
}
