<?php

namespace App\Core\Requirements;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * ADR-798 — answers « for THIS company, which documents and information are asked, and why ? », for the
 * company as a SUBJECT distinct from its employees :
 *   - company      : the organisation's own information (company-scope fields) and documents (Kbis,
 *                    insurance…) ;
 *   - legal_representative : person-level documents asked to the company's legal representative.
 * Sources (provenance, never merged into one opaque flag) :
 *   - historical activation (operational authority today : company activations snapshot at creation) ;
 *   - registry rows (preset / company / module) with subject company or legal_representative ;
 *   - verified legal references carried by registry rows (legal_reference + verified_at) — none invented.
 * Pure read : no value, file, DMS request or obligation is created ; operational compliance unchanged.
 */
final class CompanyRequirementResolver
{
    public static function resolve(Company $company): array
    {
        $items = [];
        $add = function (string $subject, string $kind, $def, array $source, bool $required) use (&$items) {
            $key = $subject.'>'.$kind.':'.$def->code;
            $items[$key] ??= ['subject' => $subject, 'kind' => $kind, 'code' => $def->code, 'label' => $def->label ?? $def->code, 'required' => false, 'sources' => []];
            $items[$key]['required'] = $items[$key]['required'] || $required;
            $items[$key]['sources'][] = $source;
        };

        // 1. Historique : activations de la société (autorité opérationnelle actuelle).
        $docTypes = DocumentType::where('scope', DocumentType::SCOPE_COMPANY)->get()->keyBy('id');
        foreach (DocumentTypeActivation::where('company_id', $company->id)->where('enabled', true)->whereIn('document_type_id', $docTypes->keys())->get() as $a) {
            $add(Requirement::SUBJECT_COMPANY, 'document', $docTypes[$a->document_type_id], ['type' => 'historical_activation', 'required' => (bool) $a->required_override], (bool) $a->required_override);
        }
        $fieldDefs = FieldDefinition::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->where('scope', FieldDefinition::SCOPE_COMPANY)->get()->keyBy('id');
        foreach (FieldActivation::where('company_id', $company->id)->where('enabled', true)->whereIn('field_definition_id', $fieldDefs->keys())->get() as $a) {
            $def = $fieldDefs[$a->field_definition_id];
            $catalogRequired = in_array($company->jobdomain_key, $def->validation_rules['required_by_jobdomains'] ?? [], true);
            $add(Requirement::SUBJECT_COMPANY, 'field', $def, ['type' => 'historical_activation', 'required' => (bool) $a->required_override || $catalogRequired,
                'catalog_required_by_jobdomain' => $catalogRequired], (bool) $a->required_override || $catalogRequired);
        }

        // 2. Registre : exigences de niveau société ou représentant légal (préréglages, société, modules).
        $rows = Requirement::where('company_id', $company->id)->whereIn('subject_scope', [Requirement::SUBJECT_COMPANY, Requirement::SUBJECT_LEGAL_REPRESENTATIVE])
            ->where('status', Requirement::STATUS_ACTIVE)->get();
        $docs = DocumentType::whereIn('id', $rows->pluck('document_type_id')->filter())->get()->keyBy('id');
        $fields = FieldDefinition::whereIn('id', $rows->pluck('field_definition_id')->filter())->get()->keyBy('id');
        foreach ($rows as $r) {
            $def = $r->target_kind === 'document' ? ($docs[$r->document_type_id] ?? null) : ($fields[$r->field_definition_id] ?? null);
            if (! $def) {
                continue;
            }
            $add($r->subject_scope, $r->target_kind, $def, ['type' => 'registry', 'source' => $r->source, 'ref' => $r->source_ref, 'version' => $r->source_version,
                'level' => $r->level, 'legal_reference' => $r->verified_at ? $r->legal_reference : null], RequirementLevel::requires($r->level) && ($r->effect ?? 'require') !== 'waive');
        }

        // État constaté (lecture) : pièce présente dans le coffre de la société / information renseignée.
        $provided = CompanyDocument::where('company_id', $company->id)->whereNotNull('file_path')->pluck('document_type_id')->flip();
        $filled = DB::table('field_values')->where('model_type', 'company')->where('model_id', $company->id)->whereNotNull('value')->pluck('field_definition_id')->flip();
        $docIds = DocumentType::pluck('id', 'code');
        $fieldIds = FieldDefinition::whereNull('company_id')->pluck('id', 'code');
        foreach ($items as &$i) {
            $i['state'] = match (true) {
                $i['subject'] === Requirement::SUBJECT_LEGAL_REPRESENTATIVE => 'asked_to_representative',
                $i['kind'] === 'document' => isset($provided[$docIds[$i['code']] ?? 0]) ? 'provided' : 'missing',
                default => isset($filled[$fieldIds[$i['code']] ?? 0]) ? 'provided' : 'missing',
            };
        }
        unset($i);
        $owner = $company->memberships()->where('role', 'owner')->with('user:id,first_name,last_name')->first();

        return [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'authority' => 'historical_activations', // la conformité opérationnelle n'est pas basculée
            'legal_representative' => $owner?->user ? ['designation' => 'owner_membership', 'name' => trim($owner->user->first_name.' '.$owner->user->last_name)] : null,
            'items' => array_values($items),
        ];
    }
}
