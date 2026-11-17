<?php

namespace App\Core\Jobdomains\Modernization;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use App\Core\Requirements\Requirement;
use Illuminate\Support\Facades\DB;

/**
 * ADR-798 — measured parity between the HISTORICAL creation (JobdomainGate::assignToCompany) and the
 * VERSIONED creation (VersionedCompanyInitializer::initializeNew) on two FICTIVE companies created inside
 * a transaction that is ALWAYS rolled back (no residue, real companies untouched).
 *
 * `fromLegacy` : the versioned presets are first drafted from the domain's legacy configuration, published
 * and recommended — inside the same rolled-back transaction — so the full path can be validated even
 * before the Platform publishes real presets.
 *
 * Every difference is classified : `voluntary` (documented product decision) or `bug` (to fix). Parity is
 * never forced : a voluntary difference stays visible.
 */
final class CreationParity
{
    private const VOLUNTARY = [
        'roles.field_config' => 'Per-role field prescriptions are not carried : a role is not a position (ADR-797).',
        'roles.doc_config' => 'Per-role document prescriptions are not carried : a role is not a position (ADR-797).',
        'requirements_registry' => 'The versioned creation also records the requirements in the registry (parallel, non operational).',
        'modules.not_entitled' => 'Recommended modules the company is not entitled to are not activated (no implicit purchase, no ambiguous flag).',
        'fields.structured_address' => 'The one-line address is replaced by the structured personal address in the starting library (ADR-820).',
    ];

    public static function measure(string $jobdomainKey, ?string $marketKey, ?string $planKey = null, bool $fromLegacy = false, ?array $confirmRoles = null): array
    {
        DB::beginTransaction();
        try {
            $jd = Jobdomain::where('key', $jobdomainKey)->firstOrFail();
            if ($fromLegacy) {
                self::presetsFromLegacy($jd);
            }
            $rbacRec = collect(PresetService::recommendationsFor($jobdomainKey, $marketKey))->firstWhere('kind', 'rbac');
            $roleKeys = $rbacRec && $rbacRec['usable'] ? array_map(fn ($r) => $r['key'], PresetService::content(PresetService::find('rbac', $rbacRec['effective_id']))['roles'] ?? []) : [];

            $legacy = self::fictive('L', $jobdomainKey, $marketKey, $planKey);
            JobdomainGate::assignToCompany($legacy, $jobdomainKey);
            $versioned = self::fictive('V', $jobdomainKey, $marketKey, $planKey);
            $summary = VersionedCompanyInitializer::initializeNew($versioned, $jobdomainKey, ['rbac' => $confirmRoles ?? $roleKeys], null, true);

            $a = self::fingerprint($legacy->fresh());
            $b = self::fingerprint($versioned->fresh());

            return ['jobdomain' => $jobdomainKey, 'market' => $marketKey, 'from_legacy' => $fromLegacy,
                'recommendations' => collect(PresetService::recommendationsFor($jobdomainKey, $marketKey))->map(fn ($r) => $r['kind'].':'.$r['family_key'].'@v'.$r['effective_version'])->all(),
                'versioned_summary' => ['modules' => $summary['modules'], 'presets' => array_map(fn ($p) => array_map(fn ($v) => is_array($v) ? count($v) : $v, $p), $summary['presets'])],
            ] + self::compare($a, $b, $fromLegacy, array_keys($summary['modules']['not_activated']));
        } finally {
            DB::rollBack(); // toujours : aucune société fictive ne subsiste
        }
    }

    private static function presetsFromLegacy(Jobdomain $jd): void
    {
        $links = [];
        foreach (['rbac', 'catalog'] as $kind) { // ADR-820 : la bibliothèque remplace la projection des exigences
            $legacy = $kind === 'rbac' ? LegacyJobdomainConfig::rbac($jd) : LegacyJobdomainConfig::catalog($jd);
            if ($legacy['items'] === []) {
                continue;
            }
            $family = 'parity_'.$jd->key.'_'.$kind;
            $preset = PresetService::createDraft($kind, ['family_key' => $family, 'label' => 'Parity '.$kind, 'jobdomain_key' => $jd->key, 'content' => $legacy['content']], null);
            PresetService::publish($preset, null);
            $links[] = ['kind' => $kind, 'family_key' => $family];
        }
        PresetService::setRecommendations($jd->key, $links);
    }

    private static function fictive(string $tag, string $jobdomainKey, ?string $marketKey, ?string $planKey): Company
    {
        $c = Company::create(['name' => "Parity {$tag}", 'slug' => 'parity-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => $jobdomainKey,
            'market_key' => $marketKey, 'plan_key' => $planKey ?? 'starter']);
        $owner = User::create(['first_name' => 'Parity', 'last_name' => $tag, 'email' => 'parity-'.uniqid().'@example.invalid', 'password' => bin2hex(random_bytes(16))]);
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return $c;
    }

    public static function fingerprint(Company $c): array
    {
        $roles = CompanyRole::where('company_id', $c->id)->with('permissions:id,key')->orderBy('key')->get()->mapWithKeys(fn ($r) => [$r->key => [
            'name' => $r->name, 'is_administrative' => (bool) $r->is_administrative, 'is_system' => (bool) $r->is_system, 'archetype' => $r->archetype,
            'required_tags' => $r->required_tags, 'permissions' => $r->permissions->pluck('key')->sort()->values()->all(),
            'field_config' => $r->field_config !== null, 'doc_config' => $r->doc_config !== null]])->all();
        $docCodes = DocumentType::pluck('code', 'id');
        $fieldCodes = FieldDefinition::pluck('code', 'id');
        $ownerRoleId = $c->memberships()->where('role', 'owner')->value('company_role_id');

        return [
            'settings' => ['jobdomain_key' => $c->jobdomain_key, 'pivot' => $c->jobdomains()->pluck('key')->all()],
            'roles' => $roles,
            'owner_role' => $ownerRoleId ? CompanyRole::find($ownerRoleId)?->key : null,
            'modules' => DB::table('company_modules')->where('company_id', $c->id)->where('is_enabled_for_company', true)->orderBy('module_key')->pluck('module_key')->all(),
            'module_reasons' => DB::table('company_module_activation_reasons')->where('company_id', $c->id)->orderBy('module_key')->get(['module_key', 'reason'])
                ->map(fn ($r) => $r->module_key.':'.$r->reason)->all(),
            'field_activations' => DB::table('field_activations')->where('company_id', $c->id)->get()
                ->mapWithKeys(fn ($a) => [$fieldCodes[$a->field_definition_id] ?? $a->field_definition_id => [(bool) $a->enabled, (bool) $a->required_override, (int) $a->order]])->sortKeys()->all(),
            'document_activations' => DB::table('document_type_activations')->where('company_id', $c->id)->get()
                ->mapWithKeys(fn ($a) => [$docCodes[$a->document_type_id] ?? $a->document_type_id => [(bool) $a->enabled, (bool) $a->required_override, (int) $a->order]])->sortKeys()->all(),
            'dashboards' => DB::table('company_dashboard_layouts')->where('company_id', $c->id)->whereNull('user_id')->get()
                ->mapWithKeys(fn ($l) => [($l->company_role_id ? CompanyRole::find($l->company_role_id)?->key : '_company') => md5((string) $l->layout_json)])->sortKeys()->all(),
            'requirements_registry' => Requirement::where('company_id', $c->id)->count(),
            'planning' => DB::table('workforce_schedule_templates')->where('company_id', $c->id)->count(),
        ];
    }

    /**
     * Same input (fromLegacy) → an unexplained difference is a BUG. Different input (presets published by the
     * Platform differ from the legacy configuration) → it is a CONFIGURATION difference, not a defect.
     */
    private static function compare(array $a, array $b, bool $sameInput, array $notEntitled = []): array
    {
        $rows = [];
        $push = function (string $dimension, string $item, $legacy, $versioned, ?string $voluntaryKey = null) use (&$rows, $sameInput) {
            $rows[] = ['dimension' => $dimension, 'item' => $item, 'legacy' => $legacy, 'versioned' => $versioned,
                'class' => $voluntaryKey ? 'voluntary' : ($sameInput ? 'bug' : 'configuration'), 'reason' => $voluntaryKey ? str_replace('.', '_', $voluntaryKey) : null,
                'explanation' => $voluntaryKey ? self::VOLUNTARY[$voluntaryKey] : null];
        };
        foreach (['settings', 'owner_role', 'field_activations', 'document_activations', 'dashboards', 'planning'] as $dim) {
            foreach (self::diffKeys($a[$dim], $b[$dim]) as [$item, $l, $v]) {
                $structured = $dim === 'field_activations' && ($item === 'address' || str_starts_with((string) $item, 'personal_address_'));
                $push($dim, $item, $l, $v, $structured ? 'fields.structured_address' : null);
            }
        }
        foreach (array_unique(array_merge(array_keys($a['roles']), array_keys($b['roles']))) as $key) {
            $l = $a['roles'][$key] ?? null;
            $v = $b['roles'][$key] ?? null;
            if (! $l || ! $v) {
                $push('roles', $key, $l ? 'present' : 'absent', $v ? 'present' : 'absent');

                continue;
            }
            foreach ($l as $prop => $value) {
                if (json_encode($value) !== json_encode($v[$prop])) {
                    $push('roles', $key.'.'.$prop, $value, $v[$prop], in_array($prop, ['field_config', 'doc_config'], true) ? 'roles.'.$prop : null);
                }
            }
        }
        foreach (array_diff($a['modules'], $b['modules']) as $m) {
            $push('modules', $m, 'active', 'inactive', in_array($m, $notEntitled, true) ? 'modules.not_entitled' : null);
        }
        foreach (array_diff($b['modules'], $a['modules']) as $m) {
            $push('modules', $m, 'inactive', 'active');
        }
        if ($a['requirements_registry'] !== $b['requirements_registry']) {
            $push('requirements_registry', 'rows', $a['requirements_registry'], $b['requirements_registry'], 'requirements_registry');
        }
        $count = fn ($c) => count(array_filter($rows, fn ($r) => $r['class'] === $c));

        return ['differences' => $rows, 'bugs' => $count('bug'), 'voluntary' => $count('voluntary'), 'configuration' => $count('configuration'),
            // Parité technique mesurable seulement à entrée identique (presets issus de l'historique).
            'technical_parity' => $sameInput ? $count('bug') === 0 : null,
            'parity_proven' => false, // les différences volontaires exigent une décision produit explicite
            'dimensions' => array_keys($a)];
    }

    /** @return list<array{0: string, 1: mixed, 2: mixed}> */
    private static function diffKeys($a, $b): array
    {
        if (! is_array($a) || ! is_array($b)) {
            return json_encode($a) === json_encode($b) ? [] : [['value', $a, $b]];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (json_encode($a[$k] ?? null) !== json_encode($b[$k] ?? null)) {
                $out[] = [(string) $k, $a[$k] ?? null, $b[$k] ?? null];
            }
        }

        return $out;
    }
}
