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
            'identity' => $jd->only(['id', 'key', 'label', 'description', 'sector', 'terminology', 'convention_refs', 'is_active', 'signup_available', 'allow_custom_fields', 'recommended_modules', 'experience']),
            // ADR-820 — profil d'expérience : ce que le domaine peut ordonner (groupes et entrées des modules ; jamais d'ajout)
            'nav_catalog' => self::navCatalog(),
            // ADR-820 — chemin de création effectif par marché actif (versionné = presets publiés ; sinon historique)
            'creation' => Market::where('is_active', true)->orderBy('key')->pluck('key')->map(fn ($m) => ['market_key' => $m,
                'path' => VersionedCompanyInitializer::shouldUse($jd->key, $m) ? 'versioned' : 'historical'])->values(),
            'markets' => Market::orderBy('key')->get(['key', 'name', 'is_active'])->map(fn ($m) => $m->only(['key', 'name', 'is_active'])
                + ['has_override' => $jd->overlays()->where('market_key', $m->key)->exists()])->values(),
            'modules' => VersionedCompanyInitializer::classifyModules((array) $jd->default_modules, $jd->key),
            'available_modules' => collect(ModuleRegistry::forScope('company'))->map(fn ($m) => ['key' => $m->key, 'name' => $m->name, 'type' => $m->type,
                'min_plan' => $m->minPlan, 'requires' => $m->requires, 'core' => str_starts_with($m->key, 'core.'),
                // ADR-820 : compatibilité déclarée par le module (son écran propriétaire la modifie), lue ici
                'compatible' => ($c = (\App\Core\Modules\PlatformModule::where('key', $m->key)->value('compatible_jobdomains_override') ?? $m->compatibleJobdomains)) === null
                    || in_array($jd->key, (array) (is_string($c) ? json_decode($c, true) : $c), true)])->values(),
            'recommendations' => PresetService::recommendationsFor($jd->key),
            'workspace' => \App\Core\Jobdomains\Modernization\JobdomainPresetWorkspace::state($jd), // ADR-799 : onglets
            'conventions' => \App\Core\Workforce\ConventionCollective::active()->orderBy('market_key')->orderBy('short_name')->get(['id', 'idcc', 'short_name', 'market_key', 'verified_at'])
                ->map(fn ($c) => ['id' => $c->id, 'idcc' => $c->idcc, 'name' => $c->short_name, 'market_key' => $c->market_key, 'verified' => $c->verified_at !== null])->values(),
            'company_list' => Company::where('jobdomain_key', $jd->key)->orderBy('name')->limit(500)->get(['id', 'name'])->map->only(['id', 'name'])->values(),
            'compatibility' => JobdomainLegacyFootprint::for($jd),
            'legacy' => ['roles' => (array) $jd->default_roles, 'documents' => (array) $jd->default_documents, 'fields' => (array) $jd->default_fields,
                'planning' => array_map(fn ($v) => is_array($v) ? count($v) : $v, (array) $jd->workforce_presets), 'editable' => (bool) config('jobdomains.legacy_editing')],
        ]);
    }

    /** @return list<array{key: string, items: list<array{key: string, title: string, module: string}>}> company nav groups and entries declared by modules */
    private static function navCatalog(): array
    {
        $groups = [];
        foreach (ModuleRegistry::forScope('company') as $m) {
            foreach ($m->capabilities->navItems ?? [] as $item) {
                $g = $item['group'] ?? 'main';
                $groups[$g][] = ['key' => $item['key'], 'title' => $item['title'] ?? $item['key'], 'module' => $m->key];
            }
        }
        $order = \App\Core\Navigation\NavGroupRegistry::orderedKeys('company');
        uksort($groups, fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        return collect($groups)->map(fn ($items, $key) => ['key' => $key, 'items' => $items])->values()->all();
    }

    public function legacyDraft(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:catalog,rbac,requirements,planning']);
        $result = LegacyPresetDraftFactory::draft(Jobdomain::findOrFail($id), $data['kind'], $request->user()?->id);

        return response()->json(['preset' => $result['preset'], 'skipped' => $result['skipped'], 'not_carried' => $result['not_carried']], 201);
    }

    public function initializationPlan(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['market' => 'nullable|exists:markets,key', 'plan' => 'nullable|string|max:50', 'from_legacy' => 'nullable|boolean']);
        $jd = Jobdomain::findOrFail($id);

        // ADR-798 : parité MESURÉE sur deux sociétés fictives dans une transaction annulée (aucun résidu).
        return response()->json(['plan' => VersionedCompanyInitializer::plan($jd->key, $data['market'] ?? null, $data['plan'] ?? null),
            'parity' => \App\Core\Jobdomains\Modernization\CreationParity::measure($jd->key, $data['market'] ?? null, $data['plan'] ?? null, (bool) ($data['from_legacy'] ?? false))]);
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

    // ── ADR-799 : presets édités dans leur onglet, sans association manuelle ni choix de version ──

    public function presetDraft(Request $request, int $id, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['catalog', 'rbac', 'requirements', 'planning'], true), 404);

        return response()->json(['draft' => \App\Core\Jobdomains\Modernization\JobdomainPresetWorkspace::draft(Jobdomain::findOrFail($id), $kind, $request->user()?->id)]);
    }

    public function presetPublish(Request $request, int $id, string $kind, int $presetId): JsonResponse
    {
        abort_unless(in_array($kind, \App\Core\Jobdomains\Modernization\JobdomainPresetWorkspace::KINDS, true), 404);

        return response()->json(\App\Core\Jobdomains\Modernization\JobdomainPresetWorkspace::publish(Jobdomain::findOrFail($id), $kind, $presetId, $request->user()?->id,
            $request->validate(['mode' => 'nullable|in:shared,variant'])['mode'] ?? null)); // ADR-803
    }
}
