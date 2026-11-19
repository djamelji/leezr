<?php

namespace App\Core\Modules\Contributions;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Requirements\Requirement;
use Illuminate\Support\Facades\DB;

/**
 * ADR-794 (L4) — Platform-side synchronisation of the module contributions (A : catalogue, B : proposals).
 *
 *  - Definitions : identity = stable code. Existing definition → LINKED (never modified) when compatible,
 *    COLLISION otherwise (different type or scope, company-owned code). Missing definition → created once
 *    in the global catalogue (no company activation : invisible in operational forms). A homonym (same
 *    label, other code) is reported, never merged.
 *  - Proposals : registry TEMPLATES (is_template, source module, version where the content last changed),
 *    never applied by themselves ; a changed item needs a higher module version ; a withdrawn item
 *    supersedes its template (company copies stay untouched).
 *  - Idempotent and serialised per module (row lock) ; never touches values, files, requests, roles,
 *    company configuration or the operational resolution (ADR-790).
 */
final class ModuleContributionCatalog
{
    /**
     * @param  array<string, ModuleManifest>|null  $manifests  null = the registry
     * @return array<string, list<array{kind: string, ref: string, outcome: string, detail: ?string}>>
     */
    public static function sync(?array $manifests = null, bool $dryRun = false): array
    {
        $report = [];
        foreach ($manifests ?? ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->contributions === [] && ! ModuleContribution::where('module_key', $key)->exists()) {
                continue;
            }
            $run = fn () => self::syncModule($key, $manifest->contributions);
            if ($dryRun) {
                DB::beginTransaction();
                try {
                    $report[$key] = $run();
                } finally {
                    DB::rollBack();
                }
            } else {
                $report[$key] = DB::transaction($run);
            }
        }

        return $report;
    }

    private static function syncModule(string $module, array $declared): array
    {
        $n = ModuleContributionSchema::normalize($declared);
        if ($n['errors']) {
            return array_map(fn ($e) => ['kind' => 'manifest', 'ref' => '-', 'outcome' => 'invalid', 'detail' => $e], $n['errors']);
        }
        $existing = ModuleContribution::where('module_key', $module)->lockForUpdate()->get()->keyBy(fn ($c) => $c->kind.':'.$c->ref);
        $out = [];
        $targets = []; // « field:code » → definition id (non-collision items of THIS module)

        foreach ($n['items'] as $item) {
            $key = $item['kind'].':'.$item['ref'];
            $row = $existing->pull($key);
            $fp = ModuleContributionSchema::fingerprint($item['payload']);
            if ($row && $row->fingerprint !== $fp && $n['version'] <= $row->version) {
                $out[] = ['kind' => $item['kind'], 'ref' => $item['ref'], 'outcome' => 'version_not_bumped', 'detail' => "content changed without a version higher than {$row->version}"];
                if ($item['kind'] !== 'requirement' && $row->status === ModuleContribution::STATUS_ACTIVE) {
                    $targets[$key] = $row->field_definition_id ?? $row->document_type_id;
                }

                continue;
            }

            [$outcome, $detail, $fieldId, $docId] = match ($item['kind']) {
                'field' => self::field($item['ref'], $item['payload']['definition'], $module),
                'document' => self::document($item['ref'], $item['payload']['definition'], $module),
                'requirement' => self::proposal($module, $item, $targets, $row, $fp, $n['version']),
            };
            if ($item['kind'] !== 'requirement' && $outcome !== 'collision') {
                $targets[$key] = $fieldId ?? $docId;
            }
            $status = $outcome === 'collision' ? ModuleContribution::STATUS_COLLISION : ModuleContribution::STATUS_ACTIVE;
            $changed = ! $row || $row->fingerprint !== $fp;
            $attrs = ['fingerprint' => $fp, 'payload' => $item['payload'], 'status' => $status, 'detail' => $detail,
                'field_definition_id' => $fieldId, 'document_type_id' => $docId];
            if ($row) {
                if (! $changed) {
                    unset($attrs['payload']); // ADR-832 — same fingerprint = same content ; MySQL JSON key order made it look dirty (rewritten every run)
                }
                $row->fill($attrs + ($changed ? ['version' => $n['version']] : []))->save();
            } else {
                ModuleContribution::create($attrs + ['module_key' => $module, 'kind' => $item['kind'], 'ref' => $item['ref'], 'version' => $n['version']]);
            }
            $out[] = ['kind' => $item['kind'], 'ref' => $item['ref'], 'outcome' => $row && ! $changed && $outcome !== 'collision' ? 'unchanged' : $outcome, 'detail' => $detail];
        }

        // Items no longer declared : withdrawn (proposal templates superseded, definitions and copies kept).
        foreach ($existing as $row) {
            if ($row->status === ModuleContribution::STATUS_WITHDRAWN) {
                continue;
            }
            if ($row->kind === 'requirement') {
                self::templatesOf($module, $row->ref)->each(fn (Requirement $t) => $t->forceFill(['status' => Requirement::STATUS_SUPERSEDED, 'effective_until' => now()])->save());
            }
            $row->forceFill(['status' => ModuleContribution::STATUS_WITHDRAWN])->save();
            $out[] = ['kind' => $row->kind, 'ref' => $row->ref, 'outcome' => 'withdrawn', 'detail' => null];
        }

        return $out;
    }

    /** @return array{0: string, 1: ?string, 2: ?int, 3: ?int} */
    private static function field(string $code, ?array $def, string $module): array
    {
        $found = FieldDefinition::where('code', $code)->orderByRaw('company_id is null desc')->first();
        if ($found) {
            if ($found->company_id !== null) {
                return ['collision', 'code_owned_by_a_company', null, null];
            }
            if ($found->scope !== FieldDefinition::SCOPE_COMPANY_USER) {
                return ['collision', 'incompatible_scope:'.$found->scope, null, null];
            }
            if ($def && $def['type'] !== $found->type) {
                return ['collision', "incompatible_type:{$found->type}≠{$def['type']}", null, null];
            }

            return ['linked', null, $found->id, null];
        }
        if (! $def) {
            return ['collision', 'unknown_definition', null, null];
        }
        $homonym = FieldDefinition::whereNull('company_id')->get(['code', 'label', 'translations'])
            ->first(fn ($d) => mb_strtolower($d->label) === mb_strtolower($def['label']) || mb_strtolower($d->translations['fr'] ?? '') === mb_strtolower($def['translations']['fr']));
        $created = FieldDefinition::create([
            'company_id' => null, 'code' => $code, 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'label' => $def['label'],
            'translations' => $def['translations'], 'type' => $def['type'], 'options' => $def['options'] ?? null,
            'validation_rules' => array_filter(($def['validation'] ?? []) + ['category' => $def['category'], 'sensitive' => (bool) ($def['sensitive'] ?? false),
                'applicable_markets' => $def['applicable_markets'] ?? null, 'contributed_by_module' => $module], fn ($v) => $v !== null),
            'is_system' => true, 'created_by_platform' => true, 'default_order' => 500,
        ]);

        return ['created', $homonym ? 'homonym_of:'.$homonym->code : null, $created->id, null];
    }

    /** @return array{0: string, 1: ?string, 2: ?int, 3: ?int} */
    private static function document(string $code, ?array $def, string $module): array
    {
        $found = DocumentType::where('code', $code)->first();
        if ($found) {
            if ($found->company_id !== null || ! $found->is_system) {
                return ['collision', 'code_owned_by_a_company', null, null];
            }
            if ($found->scope !== DocumentType::SCOPE_COMPANY_USER) {
                return ['collision', 'incompatible_scope:'.$found->scope, null, null];
            }

            return ['linked', null, null, $found->id];
        }
        if (! $def) {
            return ['collision', 'unknown_definition', null, null];
        }
        // ADR-795 : l'accès est décidé par la politique EFFECTIVE du DMS (DocumentAccessPolicy), jamais par une
        // déclaration. Un niveau plus strict que celui que le DMS appliquerait à ce code est refusé : le type
        // n'est pas créé (sinon il serait lisible avec les droits « standard » malgré la promesse du manifeste).
        $declared = $def['confidentiality'] ?? null;
        if ($declared === DocumentConfidentiality::HIGHLY_CONFIDENTIAL && DocumentConfidentiality::forTypeCode($code) !== DocumentConfidentiality::HIGHLY_CONFIDENTIAL) {
            return ['collision', 'confidentiality_not_enforced', null, null];
        }
        $homonym = DocumentType::get(['code', 'label'])->first(fn ($t) => mb_strtolower($t->label) === mb_strtolower($def['label']));
        $created = DocumentType::create([
            'code' => $code, 'label' => $def['label'], 'scope' => DocumentType::SCOPE_COMPANY_USER, 'is_system' => true,
            'requires_expiration' => (bool) ($def['requires_expiration'] ?? false), 'default_order' => 500,
            'validation_rules' => array_filter([
                'translations' => $def['translations'], 'category' => $def['category'], 'default_validity_months' => $def['default_validity_months'] ?? null,
                'renewable' => $def['renewable'] ?? null, 'declared_confidentiality' => $def['confidentiality'] ?? null,
                'max_file_size_mb' => $def['max_file_size_mb'] ?? 10, 'accepted_types' => $def['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'],
                'contributed_by_module' => $module,
            ], fn ($v) => $v !== null),
        ]);

        return ['created', $homonym ? 'homonym_of:'.$homonym->code : null, null, $created->id];
    }

    /** @return array{0: string, 1: ?string, 2: ?int, 3: ?int} */
    private static function proposal(string $module, array $item, array $targets, ?ModuleContribution $row, string $fp, int $version): array
    {
        $p = $item['payload'];
        $targetId = $targets[$p['target_kind'].':'.$p['code']] ?? null;
        if (! $targetId) {
            return ['collision', 'target_unavailable', null, null];
        }
        $column = $p['target_kind'] === 'field' ? 'field_definition_id' : 'document_type_id';
        $current = self::templatesOf($module, $item['ref'])->first();
        if ($current && $row && $row->fingerprint === $fp) {
            return ['template_unchanged', null, $p['target_kind'] === 'field' ? $targetId : null, $p['target_kind'] === 'document' ? $targetId : null];
        }
        $current?->forceFill(['status' => Requirement::STATUS_SUPERSEDED, 'effective_until' => now()])->save(); // `reason` garde la référence de la proposition
        Requirement::create([
            'company_id' => null, 'is_template' => true, 'source' => 'module', 'source_ref' => $module, 'source_version' => $version,
            'target_kind' => $p['target_kind'], $column => $targetId, 'level' => $p['level'], 'stage' => $p['stage'],
            'applicability' => $p['applicability'], 'status' => Requirement::STATUS_ACTIVE, 'reason' => 'proposal:'.$item['ref'],
        ]);

        return [$current ? 'template_updated' : 'template_created', null, $p['target_kind'] === 'field' ? $targetId : null, $p['target_kind'] === 'document' ? $targetId : null];
    }

    /** Active templates of one proposal of a module (ref kept in `reason` = « proposal:{ref} », never overwritten). */
    public static function templatesOf(string $module, string $ref)
    {
        return Requirement::whereNull('company_id')->where('is_template', true)->where('source', 'module')->where('source_ref', $module)
            ->where('reason', 'proposal:'.$ref)->where('status', Requirement::STATUS_ACTIVE)->get();
    }
}
