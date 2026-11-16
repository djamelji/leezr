<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleRegistry;
use App\Core\Requirements\RequirementLevel;

/**
 * ADR-797 (L7) — the HISTORICAL per-JobDomain configuration expressed in the item format of the versioned
 * preset families (same stable keys as the adapters), so both can be compared item by item. Pure read.
 * What has no equivalent is reported, never invented : per-role field / document prescriptions bind an
 * RBAC role, and a role is not a position (no position is ever deduced from a role).
 */
final class LegacyJobdomainConfig
{
    /** @return array{content: array, items: array<string, array>} RBAC family */
    public static function rbac(Jobdomain $jd): array
    {
        $roles = [];
        foreach ((array) $jd->default_roles as $key => $def) {
            $roles[] = array_filter(['key' => (string) $key, 'name' => $def['name'] ?? (string) $key, 'bundles' => array_values($def['bundles'] ?? []),
                'is_administrative' => (bool) ($def['is_administrative'] ?? (($def['archetype'] ?? null) === 'management'))], fn ($v) => $v !== null);
        }
        $items = [];
        foreach ($roles as $r) {
            $perms = array_values(array_unique(ModuleRegistry::resolveBundles($r['bundles'])));
            sort($perms);
            $items['role:'.$r['key']] = $r + ['permissions' => $perms];
        }

        return ['content' => ['roles' => $roles], 'items' => $items];
    }

    /** @return array{content: array, items: array<string, array>, skipped: list<string>} REQUIREMENTS family (company-wide only) */
    public static function requirements(Jobdomain $jd): array
    {
        $items = [];
        $skipped = [];
        // Les exigences portent sur des PERSONNES : les éléments de niveau société (SIRET, Kbis…) restent sur
        // le moteur historique (activés à la création), sans équivalent versionné.
        $docCodes = DocumentType::where('is_system', true)->where('scope', DocumentType::SCOPE_COMPANY_USER)->pluck('code')->flip();
        $fieldCodes = FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->pluck('code')->flip();
        foreach ((array) $jd->default_documents as $d) {
            if (! isset($docCodes[$d['code'] ?? ''])) {
                $skipped[] = 'document:'.($d['code'] ?? '?');

                continue;
            }
            // Historique : « required » au niveau du JobDomain → exigence CONFIGURABLE (jamais impérative).
            $items[] = ['kind' => 'document', 'code' => $d['code'], 'level' => ($d['required'] ?? false) ? RequirementLevel::CONFIGURABLE_REQUIRED : RequirementLevel::DEFAULT];
        }
        foreach ((array) $jd->default_fields as $f) {
            if (! isset($fieldCodes[$f['code'] ?? ''])) {
                $skipped[] = 'field:'.($f['code'] ?? '?');

                continue;
            }
            $items[] = ['kind' => 'field', 'code' => $f['code'], 'level' => RequirementLevel::DEFAULT]; // activation historique (l'obligation vit au catalogue)
        }
        $keyed = [];
        foreach ($items as $i) {
            $keyed[$i['kind'].':'.$i['code'].'@all'] = $i;
        }

        return ['content' => ['items' => $items], 'items' => $keyed, 'skipped' => $skipped];
    }

    /** @return array{content: array, items: array<string, array>} PLANNING family (same shape as workforce_presets) */
    public static function planning(Jobdomain $jd): array
    {
        $content = array_intersect_key((array) $jd->workforce_presets, array_flip(['coverage_categories', 'activity_presets', 'schedule_templates', 'week_templates']));
        $types = ['coverage_categories' => 'coverage_category', 'activity_presets' => 'activity_preset', 'schedule_templates' => 'schedule_template', 'week_templates' => 'week_template'];
        $items = [];
        foreach ($types as $section => $type) {
            foreach ($content[$section] ?? [] as $item) {
                $items[$type.':'.($item['code'] ?? '?')] = $item;
            }
        }

        return ['content' => $content, 'items' => $items];
    }

    /** Per-RBAC-role field / document prescriptions : no preset equivalent (a role is not a position). */
    public static function rolePrescriptions(Jobdomain $jd): array
    {
        $out = [];
        foreach ((array) $jd->default_roles as $key => $def) {
            $fields = collect($def['fields'] ?? [])->filter(fn ($f) => ($f['required'] ?? false) || ($f['visible'] ?? true) === false)->count();
            $docs = collect($def['doc_config'] ?? [])->filter(fn ($d) => ($d['required'] ?? false) || ($d['visible'] ?? true) === false)->count();
            if ($fields + $docs > 0) {
                $out[] = ['role' => (string) $key, 'name' => $def['name'] ?? (string) $key, 'fields' => $fields, 'documents' => $docs];
            }
        }

        return $out;
    }
}
