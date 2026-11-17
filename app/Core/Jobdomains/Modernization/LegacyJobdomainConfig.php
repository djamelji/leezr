<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleRegistry;
use App\Core\Requirements\Requirement;
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
            // ADR-798 : propriétés métier conservées (archétype, widgets) ; prescriptions par rôle exclues.
            $roles[] = array_filter(['key' => (string) $key, 'name' => $def['name'] ?? (string) $key, 'bundles' => array_values($def['bundles'] ?? []),
                'is_administrative' => (bool) ($def['is_administrative'] ?? (($def['archetype'] ?? null) === 'management')),
                'archetype' => $def['archetype'] ?? null, 'dashboard_widgets' => $def['dashboard_widgets'] ?? null], fn ($v) => $v !== null);
        }
        $items = [];
        foreach ($roles as $r) {
            $perms = array_values(array_unique(ModuleRegistry::resolveBundles($r['bundles'])));
            sort($perms);
            $items['role:'.$r['key']] = $r + ['permissions' => $perms];
        }

        return ['content' => ['roles' => $roles], 'items' => $items];
    }

    /**
     * @return array{content: array, items: array<string, array>, skipped: list<string>} REQUIREMENTS family. ADR-798 : the
     * company-level items (SIRET, Kbis…) are carried with subject `company` ; person items keep subject `person`.
     */
    public static function requirements(Jobdomain $jd): array
    {
        $items = [];
        $skipped = [];
        $docScopes = DocumentType::where('is_system', true)->pluck('scope', 'code');
        $fieldScopes = FieldDefinition::whereNull('company_id')->pluck('scope', 'code');
        $subjectOf = fn (?string $scope) => match ($scope) {
            DocumentType::SCOPE_COMPANY_USER => Requirement::SUBJECT_PERSON,
            DocumentType::SCOPE_COMPANY => Requirement::SUBJECT_COMPANY,
            default => null,
        };
        foreach ((array) $jd->default_documents as $d) {
            $subject = $subjectOf($docScopes[$d['code'] ?? ''] ?? null);
            if (! $subject) {
                $skipped[] = 'document:'.($d['code'] ?? '?');

                continue;
            }
            // Historique : « required » au niveau du JobDomain → exigence CONFIGURABLE (jamais impérative).
            $items[] = array_filter(['subject' => $subject, 'kind' => 'document', 'code' => $d['code'], 'order' => isset($d['order']) ? (int) $d['order'] : null,
                'level' => ($d['required'] ?? false) ? RequirementLevel::CONFIGURABLE_REQUIRED : (($d['recommended'] ?? false) ? RequirementLevel::RECOMMENDED : RequirementLevel::DEFAULT), 'origin' => 'legacy:'.$jd->key], fn ($v) => $v !== null);
        }
        foreach ((array) $jd->default_fields as $f) {
            $subject = $subjectOf($fieldScopes[$f['code'] ?? ''] ?? null);
            if (! $subject) {
                $skipped[] = 'field:'.($f['code'] ?? '?');

                continue;
            }
            $items[] = array_filter(['subject' => $subject, 'kind' => 'field', 'code' => $f['code'], 'order' => isset($f['order']) ? (int) $f['order'] : null,
                'level' => ($f['recommended'] ?? false) ? RequirementLevel::RECOMMENDED : RequirementLevel::DEFAULT, 'origin' => 'legacy:'.$jd->key], fn ($v) => $v !== null); // activation historique (l'obligation vit au catalogue)
        }
        $keyed = [];
        foreach ($items as $i) {
            $keyed[\App\Core\Presets\Adapters\RequirementsPresetAdapter::key($i)] = $i;
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
