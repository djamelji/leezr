<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Models\Company;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use App\Modules\Workforce\Organization\OrganizationPreset;
use Illuminate\Validation\ValidationException;

/**
 * ADR-797 (L7) — PREPARED versioned initialisation of a new company : choose the activity → get the
 * recommendations (modules + versioned presets) → preview → apply the allowed choices → customise later.
 *
 * NOT OPERATIONAL : JobdomainGate::assignToCompany stays the only creation path. `plan()` and `parity()`
 * are pure reads ; `initialize()` refuses to run unless `jobdomains.versioned_init.enabled` is true, and
 * even then it never activates a module (paid or not), never grants a sensitive role without explicit
 * selection (RBAC adapter rule), never touches an existing customisation (adapters are additive).
 */
final class VersionedCompanyInitializer
{
    public static function plan(string $jobdomainKey, ?string $marketKey): array
    {
        $jd = Jobdomain::where('key', $jobdomainKey)->firstOrFail();
        $presets = [];
        foreach (PresetService::recommendationsFor($jobdomainKey, $marketKey) as $rec) {
            $model = $rec['usable'] ? PresetService::find($rec['kind'], $rec['effective_id']) : null;
            $items = $model ? PresetService::adapter($rec['kind'])->items(PresetService::content($model)) : [];
            $presets[] = $rec + ['items' => array_map(fn ($k, $i) => ['key' => $k, 'label' => $i['name'] ?? $i['code'] ?? $i['title'] ?? $k], array_keys($items), $items)];
        }

        return ['jobdomain' => ['key' => $jd->key, 'label' => $jd->label], 'market_key' => $marketKey,
            'modules' => self::modules((array) $jd->default_modules), 'presets' => $presets,
            'operational' => (bool) config('jobdomains.versioned_init.enabled')];
    }

    /** Historical creation (assignToCompany) vs versioned initialisation, family by family — no write. */
    public static function parity(string $jobdomainKey, ?string $marketKey): array
    {
        $resolved = JobdomainPresetResolver::resolve($jobdomainKey, $marketKey);
        $recs = collect(PresetService::recommendationsFor($jobdomainKey, $marketKey))->keyBy('kind');
        $versioned = function (string $kind) use ($recs) {
            $r = $recs->get($kind);

            return $r && $r['usable'] ? PresetService::adapter($kind)->items(PresetService::content(PresetService::find($kind, $r['effective_id']))) : null;
        };
        $legacyRoles = [];
        foreach ($resolved->roles as $key => $def) {
            $perms = array_values(array_unique(ModuleRegistry::resolveBundles($def['bundles'] ?? [])));
            sort($perms);
            $legacyRoles['role:'.$key] = ['name' => $def['name'] ?? $key, 'permissions' => $perms];
        }
        $legacyReq = [];
        foreach ($resolved->documents as $d) {
            $legacyReq['document:'.$d['code'].'@all'] = ['required' => (bool) ($d['required'] ?? false)];
        }
        foreach ($resolved->fields as $f) {
            $legacyReq['field:'.$f['code'].'@all'] = ['required' => false];
        }

        $families = [
            'rbac' => self::diff($legacyRoles, $versioned('rbac'), fn ($i) => ['name' => $i['name'] ?? null, 'permissions' => $i['permissions'] ?? []]),
            'requirements' => self::diff($legacyReq, ($v = $versioned('requirements')) === null ? null
                : array_map(fn ($i) => ['required' => ($i['level'] ?? null) === \App\Core\Requirements\RequirementLevel::CONFIGURABLE_REQUIRED], $v), fn ($i) => $i),
            // Le planning n'est jamais créé par l'historique : importé ensuite, à la demande (WorkforcePresetImportService).
            'planning' => ['status' => 'not_created_historically', 'versioned_items' => count($versioned('planning') ?? [])],
            'organization' => ['status' => 'not_created_historically', 'versioned' => $recs->has('organization')],
            'modules' => ['status' => 'behaviour_differs', 'historical' => 'activated_at_creation', 'versioned' => 'recommended_only_never_activated',
                'keys' => array_values($resolved->modules)],
        ];
        $blocking = array_values(array_filter(['rbac', 'requirements'], fn ($k) => ($families[$k]['status'] ?? null) !== 'equivalent'));

        // Parité JAMAIS déclarée automatiquement : même avec rôles et exigences équivalents, les modules diffèrent
        // par conception (activés par l'historique, seulement recommandés ici) — décision produit requise.
        return ['families' => $families, 'parity_proven' => false, 'items_equivalent' => $blocking === [], 'blocking' => $blocking,
            'decision_required' => ['modules_activation_at_creation']];
    }

    /**
     * @param  array<string, ?list<string>>  $selection  kind => selected item keys (null = adapter default : additive, non-sensitive)
     */
    public static function initialize(Company $company, array $selection, ?int $actorId): array
    {
        if (! config('jobdomains.versioned_init.enabled')) {
            throw ValidationException::withMessages(['versioned_init' => 'Versioned initialisation is prepared but not operational (jobdomains.versioned_init.enabled).']);
        }
        $out = [];
        foreach (PresetService::recommendationsFor((string) $company->jobdomain_key, $company->market_key) as $rec) {
            if (! $rec['usable'] || ! array_key_exists($rec['kind'], $selection)) {
                continue; // seulement les familles explicitement choisies
            }
            $preset = PresetService::find($rec['kind'], $rec['effective_id']);
            $out[$rec['kind']] = PresetService::apply($company, $preset, $selection[$rec['kind']], 'versioned_init', $actorId);
        }

        return $out;
    }

    public static function modules(array $keys): array
    {
        return array_map(function ($key) {
            $m = ModuleRegistry::definitions()[$key] ?? null;

            return ['key' => $key, 'name' => $m?->name ?? $key, 'exists' => $m !== null, 'globally_enabled' => $m ? ModuleGate::isEnabledGlobally($key) : false,
                'min_plan' => $m?->minPlan, 'requires' => $m ? array_values(ModuleActivationEngine::collectTransitiveRequires($key)) : [],
                'commercial_status' => $m?->commercialStatus];
        }, array_values($keys));
    }

    private static function diff(array $legacy, ?array $versioned, callable $norm): array
    {
        if ($versioned === null) {
            return ['status' => $legacy === [] ? 'equivalent' : 'no_versioned_preset', 'legacy_only' => array_keys($legacy), 'versioned_only' => [], 'different' => []];
        }
        $different = array_values(array_filter(array_keys(array_intersect_key($legacy, $versioned)), fn ($k) => json_encode($norm($legacy[$k])) !== json_encode($norm($versioned[$k]))));
        $legacyOnly = array_values(array_diff(array_keys($legacy), array_keys($versioned)));
        $versionedOnly = array_values(array_diff(array_keys($versioned), array_keys($legacy)));

        return ['status' => $different === [] && $legacyOnly === [] && $versionedOnly === [] ? 'equivalent' : 'differs',
            'legacy_only' => $legacyOnly, 'versioned_only' => $versionedOnly, 'different' => $different];
    }
}
