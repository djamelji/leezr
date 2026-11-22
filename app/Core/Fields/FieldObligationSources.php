<?php

namespace App\Core\Fields;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Requirements\RequirementAuthority;

/**
 * ADR-858 (Leezr 5.0 L5) — WHERE the obligation of each person field comes from, read from the EXISTING sources
 * the field engine already applies (FieldResolverService / FieldValidationService / MandatoryContext). Read only,
 * no new rule : it makes the inheritance explicit so that each rule has ONE place where it is edited.
 *
 *  - platform  : `validation_rules.required` (Platform › Catalogue global — required everywhere)
 *  - modules   : `validation_rules.required_by_modules` ∩ active modules (Platform › Catalogue, module needs)
 *  - jobdomain : `validation_rules.required_by_jobdomains` (Platform › JobDomain) — ignored by a company whose
 *                requirements authority is the POSITION (ADR-820 explicit_only), exactly like MandatoryContext
 *  - company   : `field_activations.required_override` on an ENABLED activation (Company › Catalogue — « required
 *                for the whole company »)
 *  - roles     : `company_roles.field_config[].required` — historical per-role overlay (ADR-164), still applied by
 *                the form for the role of the account ; `hidden_for_roles` = visible:false in that overlay
 * Position requirements (registry `job_role:*`) are NOT here : the catalogue already reports them (`used_by`).
 */
final class FieldObligationSources
{
    /** @return array<string, array{platform: bool, modules: list<string>, jobdomain: bool, company: bool, company_dormant: bool, roles: list<string>, role_keys: list<string>, hidden_for_roles: list<string>}> keyed by field code */
    public static function forCompany(Company $company): array
    {
        $explicitOnly = RequirementAuthority::isPosition($company->id);
        $activeModules = CompanyModule::where('company_id', $company->id)->where('is_enabled_for_company', true)->pluck('module_key')->all();
        $activations = FieldActivation::where('company_id', $company->id)->get()->keyBy('field_definition_id');
        $roles = CompanyRole::where('company_id', $company->id)->whereNotNull('field_config')->get(['id', 'key', 'name', 'field_config']);

        $byRole = [];
        foreach ($roles as $role) {
            foreach ((array) $role->field_config as $entry) {
                if (! is_array($entry) || empty($entry['code'])) {
                    continue;
                }
                if (($entry['visible'] ?? true) === false) {
                    $byRole[$entry['code']]['hidden'][] = $role->name;
                    $byRole[$entry['code']]['hidden_keys'][] = $role->key;
                } elseif (! empty($entry['required'])) {
                    $byRole[$entry['code']]['required'][] = $role->name;
                    $byRole[$entry['code']]['required_keys'][] = $role->key; // ADR-859 : the engine matches the role KEY
                }
            }
        }

        $out = [];
        $defs = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->get();
        foreach ($defs as $d) {
            $rules = $d->validation_rules ?? [];
            $a = $activations->get($d->id);
            $out[$d->code] = [
                'platform' => (bool) ($rules['required'] ?? false),
                'modules' => array_values(array_intersect($rules['required_by_modules'] ?? [], $activeModules)),
                'jobdomain' => ! $explicitOnly && $company->jobdomain_key && in_array($company->jobdomain_key, $rules['required_by_jobdomains'] ?? [], true),
                'company' => (bool) ($a?->enabled && $a?->required_override),
                'company_dormant' => (bool) ($a && ! $a->enabled && $a->required_override), // stored, but the field is not proposed
                'roles' => $byRole[$d->code]['required'] ?? [],
                'role_keys' => $byRole[$d->code]['required_keys'] ?? [],
                'hidden_for_roles' => $byRole[$d->code]['hidden'] ?? [],
                'hidden_role_keys' => $byRole[$d->code]['hidden_keys'] ?? [],
            ];
        }

        return $out;
    }
}
