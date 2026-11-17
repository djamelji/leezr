<?php

namespace App\Core\Requirements;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ADR-791 (L1) — READ-ONLY comparison between the operational resolution (ADR-790 :
 * DocumentResolverService::requirementMap, FieldResolverService) and the parallel resolver.
 *
 * The registry is empty until L2+, so the parallel side resolves an IN-MEMORY PROJECTION of the
 * historical configuration that the target architecture can represent (nothing is persisted) :
 *  - activation `required_override` (documents, fields)        → source company, configurable_required
 *  - catalogue `required_by_modules` (documents, fields)        → source module
 *  - catalogue `required_by_jobdomains` (fields)                → source jobdomain
 * Rules tied to the RBAC role (doc_config, field_config, tags × role) are NOT projected : the target
 * architecture does not take requirements from the RBAC role. Every gap is classified, never hidden.
 */
final class RequirementParityComparator
{
    public const EXPECTED = 'expected_target_difference';
    public const BUG = 'resolution_bug';
    public const AMBIGUOUS = 'ambiguous_historical_data';
    public const MISSING = 'missing_configuration';
    public const ARBITRATION = 'product_arbitration';

    /** @return list<Requirement> unsaved rows (negative ids) */
    public static function projection(Company $company): array
    {
        $rows = [];
        $id = 0;
        $make = function (array $attrs) use (&$rows, &$id) {
            $r = new Requirement($attrs);
            $r->setAttribute('id', --$id);
            $r->setAttribute('status', Requirement::STATUS_ACTIVE);
            $r->assertValid();
            $rows[] = $r;
        };

        $docActivations = DocumentTypeActivation::where('company_id', $company->id)->where('enabled', true)->get()->keyBy('document_type_id');
        $docTypes = DocumentType::whereIn('id', $docActivations->keys())->whereNull('archived_at')->get();
        foreach ($docTypes as $type) {
            if ($docActivations[$type->id]->required_override) {
                $make(['company_id' => $company->id, 'target_kind' => 'document', 'document_type_id' => $type->id, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'company', 'source_ref' => 'activation:'.$docActivations[$type->id]->id]);
            }
            foreach ($type->validation_rules['required_by_modules'] ?? [] as $module) {
                $make(['company_id' => null, 'target_kind' => 'document', 'document_type_id' => $type->id, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'module', 'source_ref' => $module]);
            }
        }

        $fieldActivations = FieldActivation::where('company_id', $company->id)->where('enabled', true)->get()->keyBy('field_definition_id');
        $defs = FieldDefinition::whereIn('id', $fieldActivations->keys())->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();
        foreach ($defs as $def) {
            if ($fieldActivations[$def->id]->required_override) {
                $make(['company_id' => $company->id, 'target_kind' => 'field', 'field_definition_id' => $def->id, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'company', 'source_ref' => 'activation:'.$fieldActivations[$def->id]->id]);
            }
            foreach ($def->validation_rules['required_by_modules'] ?? [] as $module) {
                $make(['company_id' => null, 'target_kind' => 'field', 'field_definition_id' => $def->id, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'module', 'source_ref' => $module]);
            }
            foreach ($def->validation_rules['required_by_jobdomains'] ?? [] as $jd) {
                $make(['company_id' => null, 'target_kind' => 'field', 'field_definition_id' => $def->id, 'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'source' => 'jobdomain', 'source_ref' => $jd]);
            }
        }

        return $rows;
    }

    /**
     * @param  iterable<Requirement>|null  $rows  null = in-memory projection of the historical configuration
     */
    public static function compare(Company $company, ?iterable $rows = null): array
    {
        $resolver = EffectiveRequirementResolver::forCompany($company, $rows ?? self::projection($company));
        $subjects = $resolver->subjects();

        $roleKeys = DB::table('memberships')->leftJoin('company_roles', 'company_roles.id', '=', 'memberships.company_role_id')
            ->where('memberships.company_id', $company->id)->pluck('company_roles.key', 'memberships.user_id');
        $roles = CompanyRole::where('company_id', $company->id)->get()->keyBy('key');
        $docCodes = DocumentType::pluck('code', 'id');
        $fieldCodes = FieldDefinition::pluck('code', 'id');
        $fieldIdsByCode = FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->pluck('id', 'code');

        // Modules marqués actifs dans company_modules (lu par l'historique) mais inactifs pour ModuleGate
        // (autorité d'activation, lue par la résolution parallèle) : drapeau historique ambigu.
        $staleModules = DB::table('company_modules')->where('company_id', $company->id)->where('is_enabled_for_company', true)->pluck('module_key')
            ->reject(fn ($k) => \App\Core\Modules\ModuleGate::isActive($company, $k))->values()->all();
        $rulesByTarget = [];
        foreach (DocumentType::all(['id', 'validation_rules']) as $t) {
            $rulesByTarget['document:'.$t->id] = $t->validation_rules ?? [];
        }
        foreach (FieldDefinition::whereNull('company_id')->get(['id', 'validation_rules']) as $d) {
            $rulesByTarget['field:'.$d->id] = $d->validation_rules ?? [];
        }

        $maps = [];
        $report = ['company_id' => $company->id, 'subjects' => count($subjects), 'accountless' => 0,
            'requirements' => ['historical' => 0, 'parallel' => 0], 'matches' => 0, 'differences' => 0,
            'by_category' => array_fill_keys([self::EXPECTED, self::BUG, self::AMBIGUOUS, self::MISSING, self::ARBITRATION], 0),
            'subjects_without_configuration' => 0, 'conflicting_sources' => 0, 'not_representable' => [], 'samples' => []];

        foreach ($subjects as $s) {
            $roleKey = $s['user_id'] ? ($roleKeys[$s['user_id']] ?? null) : null;
            $role = $roleKey ? $roles->get($roleKey) : null;
            $s['user_id'] === null && $report['accountless']++;

            // Historique (source de vérité opérationnelle) : documents par la décision ADR-790, champs par le résolveur de champs.
            $maps[$roleKey ?? ''] ??= DocumentResolverService::requirementMap($company->id, $roleKey, $company->market_key, DocumentType::SCOPE_COMPANY_USER);
            $old = [];
            foreach ($maps[$roleKey ?? ''] as $typeId => $e) {
                if ($e['visible'] && $e['required']) {
                    $old['document:'.$typeId] = true;
                }
            }
            if ($s['user_id'] && ($user = User::find($s['user_id']))) {
                foreach (FieldResolverService::resolve(model: $user, scope: FieldDefinition::SCOPE_COMPANY_USER, companyId: $company->id, roleKey: $roleKey, marketKey: $company->market_key) as $f) {
                    if ($f['required'] && isset($fieldIdsByCode[$f['code']])) {
                        $old['field:'.$fieldIdsByCode[$f['code']]] = true;
                    }
                }
            }

            $new = $resolver->resolveFor($s);
            $newRequired = array_filter($new, fn ($r) => $r['required']);
            $new === [] && $report['subjects_without_configuration']++;
            $report['conflicting_sources'] += count(array_filter($new, fn ($r) => count(array_unique(array_column(array_filter($r['contributions'], fn ($c) => $c['status'] === 'applicable'), 'source'))) > 1));
            $report['requirements']['historical'] += count($old);
            $report['requirements']['parallel'] += count($newRequired);

            foreach (array_unique(array_merge(array_keys($old), array_keys($newRequired))) as $key) {
                $inOld = isset($old[$key]);
                $inNew = isset($newRequired[$key]);
                if ($inOld && $inNew) {
                    $report['matches']++;

                    continue;
                }
                [$kind, $id] = explode(':', $key);
                $code = $kind === 'document' ? ($docCodes[$id] ?? $id) : ($fieldCodes[$id] ?? $id);
                // ADR-817 : exigence posée par la société elle-même (poste ou tous les postes) dans le registre.
                $byCompany = $inNew && collect($newRequired[$key]['contributions'] ?? [])->contains(fn ($c) => ($c['status'] ?? null) === 'applicable' && ($c['source'] ?? null) === 'company');
                [$category, $cause] = self::classify($kind, $code, $inOld, $role, $s, array_values(array_intersect($rulesByTarget[$key]['required_by_modules'] ?? [], $staleModules)), $byCompany);
                $report['differences']++;
                $report['by_category'][$category]++;
                $sampleKey = $category.'|'.$kind.':'.$code.'|'.($roleKey ?? ($s['user_id'] ? 'sans rôle' : 'sans compte'));
                $report['samples'][$sampleKey] ??= ['category' => $category, 'target' => $kind.':'.$code, 'historical' => $inOld, 'parallel' => $inNew, 'role' => $roleKey, 'account' => $s['user_id'] !== null, 'job_role' => $s['job_role_key'], 'cause' => $cause, 'count' => 0];
                $report['samples'][$sampleKey]['count']++;
            }
        }

        foreach ($roles as $key => $role) {
            if ($role->doc_config || $role->field_config || $role->required_tags) {
                $report['not_representable'][] = ['role' => $key, 'doc_config' => count($role->doc_config ?? []), 'field_config' => count($role->field_config ?? []), 'required_tags' => $role->required_tags ?? []];
            }
        }
        $report['samples'] = array_values($report['samples']);

        return $report;
    }

    /**
     * ADR-817 (L1) — parity once positions carry requirements : the historical projection (company /
     * module / jobdomain rules) PLUS the company's own registry rows (positions, « all »). Measures how far
     * the position-based resolution is from today's role-based operational resolution. Read-only.
     */
    public static function compareWithRegistry(Company $company): array
    {
        $registry = Requirement::query()->where('company_id', $company->id)->where('subject_scope', Requirement::SUBJECT_PERSON)->get()->all();

        return self::compare($company, array_merge(self::projection($company), $registry));
    }

    /** @return array{0: string, 1: string} */
    private static function classify(string $kind, string $code, bool $inOld, ?CompanyRole $role, array $s, array $staleModules = [], bool $byCompany = false): array
    {
        if ($inOld && $staleModules !== []) {
            return [self::AMBIGUOUS, 'exigé par le module « '.implode(', ', $staleModules).' » marqué actif dans company_modules sans raison d\'activation (inactif pour ModuleGate)'];
        }
        $config = collect($kind === 'document' ? ($role?->doc_config ?? []) : ($role?->field_config ?? []))->keyBy('code')->get($code);
        $tags = $role?->required_tags ?? [];

        if ($inOld) {
            // Exigé aujourd'hui, pas par la résolution cible.
            if ($config && ($config['required'] ?? false)) {
                return [self::MISSING, "exigé par la configuration du rôle RBAC « {$role->key} » — à recréer comme exigence de poste (L2)"];
            }
            if ($tags !== []) {
                return [self::EXPECTED, "exigé via les étiquettes du rôle RBAC « {$role->key} » (".implode(', ', $tags).') — le rôle n\'est plus une source'];
            }

            return [self::BUG, 'exigé par l\'historique sans cause identifiée'];
        }

        // Exigé par la résolution cible, pas aujourd'hui.
        if ($config && (($config['visible'] ?? true) === false || ($config['required'] ?? null) === false)) {
            return [self::ARBITRATION, "exigé par l'entreprise mais masqué ou rendu facultatif par le rôle RBAC « {$role->key} »"];
        }
        if ($kind === 'field' && $s['user_id'] === null) {
            return [self::AMBIGUOUS, 'salarié sans compte : les valeurs de champs vivent sur le compte (aucun historique comparable avant L5)'];
        }
        if ($byCompany) {
            return [self::EXPECTED, $s['job_role_id'] ? 'exigé par le poste (configuration de l\'entreprise) — nouveau modèle, absent de l\'historique par rôle' : 'exigé par l\'entreprise pour tous les postes — absent de l\'historique par rôle'];
        }

        return [self::BUG, 'exigé par la résolution cible sans cause identifiée'];
    }
}
