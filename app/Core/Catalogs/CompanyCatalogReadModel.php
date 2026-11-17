<?php

namespace App\Core\Catalogs;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\Satisfaction\DocumentProofRegistry;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldOwnership;
use App\Core\Fields\FieldResolverService;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Models\Company;
use App\Core\Modules\Contributions\CompanyModuleContributions;
use App\Core\Modules\ModuleRegistry;
use App\Core\Requirements\Requirement;
use App\Core\Workforce\JobRole;

/**
 * ADR-819 (Company 2.0) — ONE read of what a company can collect : person fields and document types, each
 * with its ORIGIN (standard, recommended by the business profile, brought by a module, created by the
 * company), its availability and WHERE it is used (positions that require it). No second catalogue
 * engine : it reads the existing definitions, activations, module manifests, JobDomain presets and the
 * requirements registry. Availability ≠ obligation : nothing here makes anything mandatory.
 *
 * Status of an entry : active (offered in the company forms) | available (can be activated) |
 * recommended (proposed by the business profile, not activated). Module contributions reference canonical
 * definitions : a module origin is informative (with the module state), it never hides a standard field.
 */
final class CompanyCatalogReadModel
{
    public static function forCompany(Company $company): array
    {
        $market = $company->market_key;
        $inMarket = fn ($rules) => empty($rules['applicable_markets']) || in_array($market, $rules['applicable_markets'], true);
        $locale = FieldResolverService::requestLocale();

        // ── Origins : JobDomain presets (activated / recommended) and module contributions ──
        $presets = $company->jobdomain_key ? JobdomainPresetResolver::resolve($company->jobdomain_key, $market) : null;
        $jdFields = collect($presets?->fields ?? [])->keyBy('code');
        $jdDocs = collect($presets?->documents ?? [])->keyBy('code');
        $moduleRefs = ['fields' => [], 'documents' => []];
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->scope !== 'company' || empty($manifest->contributions)) {
                continue;
            }
            $state = CompanyModuleContributions::lifecycle($company, $key);
            foreach (['fields', 'documents'] as $kind) {
                foreach ($manifest->contributions[$kind] ?? [] as $c) {
                    $moduleRefs[$kind][$c['ref']][] = ['module' => $key, 'name' => $manifest->name, 'active' => $state === 'active'];
                }
            }
        }

        // ── Where used : positions requiring each item (company requirements scoped to positions) ──
        $positions = JobRole::withoutGlobalScopes()->where('company_id', $company->id)->pluck('title', 'id');
        $usage = ['field' => [], 'document' => []];
        Requirement::where('company_id', $company->id)->where('source', 'company')->where('status', Requirement::STATUS_ACTIVE)
            ->where('effect', Requirement::EFFECT_REQUIRE)->with(['fieldDefinition:id,code', 'documentType:id,code'])->get()
            ->each(function (Requirement $r) use (&$usage, $positions) {
                $code = $r->target_kind === Requirement::KIND_FIELD ? $r->fieldDefinition?->code : $r->documentType?->code;
                if (! $code) {
                    return;
                }
                $ids = $r->applicability['job_role_ids'] ?? [];
                $usage[$r->target_kind][$code] ??= ['everyone' => false, 'positions' => []];
                if ($ids === []) {
                    $usage[$r->target_kind][$code]['everyone'] = true;
                }
                foreach ($ids as $id) {
                    if (isset($positions[$id])) {
                        $usage[$r->target_kind][$code]['positions'][$id] = ['id' => (int) $id, 'title' => $positions[$id]];
                    }
                }
            });
        $usedBy = fn (string $kind, string $code) => [
            'everyone' => $usage[$kind][$code]['everyone'] ?? false,
            'positions' => array_values($usage[$kind][$code]['positions'] ?? []),
        ];

        $origins = function (?int $ownerCompanyId, bool $system, string $code, $jd, array $mods) use ($company) {
            $out = [];
            if ($ownerCompanyId === $company->id) {
                $out[] = ['kind' => 'custom'];
            } elseif ($system || $ownerCompanyId === null) {
                $out[] = ['kind' => 'standard'];
            }
            if ($jd) {
                $out[] = ['kind' => 'jobdomain', 'recommended' => (bool) ($jd['recommended'] ?? false)];
            }
            foreach ($mods as $m) {
                $out[] = ['kind' => 'module', 'module' => $m['module'], 'name' => $m['name'], 'active' => $m['active']];
            }

            return $out;
        };
        $status = function (bool $active, $jd, array $mods, bool $custom) {
            if ($active) {
                return 'active';
            }
            return ($jd['recommended'] ?? false) ? 'recommended' : 'available';
        };

        // ── Fields (person file) ──
        $activeFields = FieldActivation::where('company_id', $company->id)->where('enabled', true)->pluck('field_definition_id')->flip();
        $fields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->orderBy('default_order')->get()
            ->filter(fn ($d) => $inMarket($d->validation_rules ?? []))
            ->map(function (FieldDefinition $d) use ($company, $activeFields, $jdFields, $moduleRefs, $origins, $status, $usedBy, $locale) {
                $jd = $jdFields->get($d->code);
                $mods = $moduleRefs['fields'][$d->code] ?? [];
                $custom = (int) $d->company_id === $company->id;

                return [
                    'id' => $d->id,
                    'code' => $d->code,
                    'label' => $d->resolvedLabel($locale),
                    'type' => $d->type,
                    'options' => $d->options,
                    'description' => $d->validation_rules['description'] ?? null,
                    'custom' => $custom,
                    'origins' => $origins($d->company_id ? (int) $d->company_id : null, (bool) $d->is_system, $d->code, $jd, $mods),
                    'status' => $status($activeFields->has($d->id), $jd, $mods, $custom),
                    'ownership' => FieldOwnership::of($d),
                    'sensitive' => (bool) ($d->validation_rules['sensitive'] ?? false),
                    'used_by' => $usedBy('field', $d->code),
                ];
            })->values()->all();

        // ── Document types (person documents) ──
        $docActivations = DocumentTypeActivation::where('company_id', $company->id)->get()->keyBy('document_type_id');
        $activeDocs = $docActivations->filter(fn ($a) => $a->enabled)->map(fn () => true);
        $provable = DocumentProofRegistry::provableCodes();
        $documents = DocumentType::where('scope', DocumentType::SCOPE_COMPANY_USER)->whereNull('archived_at')
            ->where(fn ($q) => $q->where('is_system', true)->orWhere('company_id', $company->id))
            ->orderBy('default_order')->get()
            ->filter(fn ($t) => $inMarket($t->validation_rules ?? []))
            ->map(function (DocumentType $t) use ($company, $activeDocs, $docActivations, $jdDocs, $moduleRefs, $origins, $status, $usedBy, $provable) {
                $jd = $jdDocs->get($t->code);
                $mods = $moduleRefs['documents'][$t->code] ?? [];
                $custom = (int) $t->company_id === $company->id;
                $rules = $t->validation_rules ?? [];

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'label' => $t->label,
                    'description' => $rules['description'] ?? null,
                    'custom' => $custom,
                    'origins' => $origins($t->company_id ? (int) $t->company_id : null, (bool) $t->is_system, $t->code, $jd, $mods),
                    'status' => $status($activeDocs->has($t->id), $jd, $mods, $custom),
                    'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'],
                    'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
                    'requires_expiration' => (bool) $t->requires_expiration,
                    'confidentiality' => DocumentConfidentiality::forTypeCode($t->code),
                    // Sources de satisfaction : dépôt d'une pièce, et toute source module compatible.
                    'sources' => array_values(array_merge(['upload'], in_array($t->code, $provable, true) ? ['module_proof'] : [])),
                    'used_by' => $usedBy('document', $t->code),
                    // Règle historique « exigé de tous » (activation), distincte des exigences par poste.
                    'required_for_everyone' => (bool) ($docActivations->get($t->id)?->required_override ?? false),
                    'order' => (int) ($docActivations->get($t->id)?->order ?? $t->default_order ?? 0),
                ];
            })->values()->all();

        return [
            'fields' => $fields,
            'documents' => $documents,
            'jobdomain' => $company->jobdomain_key,
            'can_create_fields' => (bool) $company->jobdomain?->allow_custom_fields,
            'field_types' => FieldDefinition::COMPANY_TYPES,
        ];
    }
}
