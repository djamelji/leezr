<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Models\Company;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;

/**
 * ADR-797 (L7) — compatibility diagnostic of ONE JobDomain : for each legacy configuration family, the
 * recommended versioned preset (if any) and an item-level correspondence :
 *   certain   — same item, same content ;
 *   partial   — same item, different content ;
 *   legacy_only — historical item without versioned equivalent ;
 *   preset_only — item of the preset absent from the historical configuration ;
 *   human_decision — no possible equivalent (per-role prescriptions : a role is not a position).
 * Pure read ; nothing is converted.
 */
final class JobdomainLegacyFootprint
{
    public static function for(Jobdomain $jd): array
    {
        $recs = collect(PresetService::recommendationsFor($jd->key))->whereNull('market_key')->keyBy('kind');
        $families = [];
        foreach (['rbac' => LegacyJobdomainConfig::rbac($jd), 'requirements' => LegacyJobdomainConfig::requirements($jd), 'planning' => LegacyJobdomainConfig::planning($jd)] as $kind => $legacy) {
            $rec = $recs->get($kind);
            $preset = $rec && $rec['effective_id'] ? Preset::find($rec['effective_id']) : null;
            $presetItems = $preset ? PresetService::adapter($kind)->items((array) $preset->content) : [];
            $families[$kind] = self::compare($kind, $legacy['items'], $presetItems) + [
                'legacy_count' => count($legacy['items']), 'skipped' => $legacy['skipped'] ?? [],
                'preset' => $rec ? ['family_key' => $rec['family_key'], 'version' => $rec['effective_version'], 'status' => $rec['effective_status'], 'label' => $rec['label']] : null,
            ];
        }
        $prescriptions = LegacyJobdomainConfig::rolePrescriptions($jd);
        $overlays = JobdomainMarketOverlay::where('jobdomain_key', $jd->key)->get();

        return [
            'families' => $families,
            'role_prescriptions' => ['class' => 'human_decision', 'items' => $prescriptions,
                'reason' => 'per_role_prescriptions_bind_rbac_roles_not_positions'],
            'market_overrides' => $overlays->map(fn ($o) => ['market_key' => $o->market_key,
                'modules' => (bool) ($o->override_modules || $o->remove_modules), 'fields' => (bool) ($o->override_fields || $o->remove_fields),
                'documents' => (bool) ($o->override_documents || $o->remove_documents), 'roles' => (bool) ($o->override_roles || $o->remove_roles)])->values()->all(),
            // Moteurs historiques encore actifs, lus tels quels jusqu'à la bascule.
            'legacy_engines' => [
                ['engine' => 'company_creation', 'reads' => ['default_modules', 'default_fields', 'default_documents', 'default_roles', 'overlays'], 'active' => true],
                ['engine' => 'preset_reconciliation', 'reads' => ['default_roles'], 'active' => true, 'manual_only' => true],
                ['engine' => 'document_compliance', 'reads' => ['company document activations (snapshot at creation)'], 'active' => true],
                ['engine' => 'workforce_preset_import', 'reads' => ['workforce_presets'], 'active' => true],
            ],
            'companies' => Company::where('jobdomain_key', $jd->key)->count(),
            'versioned_init_enabled' => (bool) config('jobdomains.versioned_init.enabled'),
        ];
    }

    private static function compare(string $kind, array $legacy, array $preset): array
    {
        $norm = fn (array $i) => $kind === 'rbac' ? ['name' => $i['name'] ?? null, 'permissions' => $i['permissions'] ?? []]
            : ($kind === 'requirements' ? ['level' => $i['level'] ?? null] : $i);
        $rows = [];
        foreach ($legacy as $key => $item) {
            $rows[] = ['key' => $key, 'label' => $item['name'] ?? $item['code'] ?? $key, 'class' => ! isset($preset[$key]) ? 'legacy_only'
                : (json_encode($norm($item)) === json_encode($norm($preset[$key])) ? 'certain' : 'partial')];
        }
        foreach (array_diff_key($preset, $legacy) as $key => $item) {
            $rows[] = ['key' => $key, 'label' => $item['name'] ?? $item['code'] ?? $key, 'class' => 'preset_only'];
        }
        $counts = array_count_values(array_column($rows, 'class'));

        return ['items' => $rows, 'counts' => $counts + array_fill_keys(['certain', 'partial', 'legacy_only', 'preset_only'], 0)];
    }
}
