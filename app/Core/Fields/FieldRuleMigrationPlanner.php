<?php

namespace App\Core\Fields;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Requirements\RequirementAuthority;
use Illuminate\Support\Facades\DB;

/**
 * ADR-858 (Leezr 5.0 L5) — audit and REVERSIBLE migration of the stored person-field rules, after « Outils → Champs »
 * was retired. Read first : every stored rule is classified from FieldObligationSources (the engine's own inputs).
 *
 * Only REDUNDANT rules are ever changed — a rule whose field is required anyway by a Platform source that applies to
 * this company (catalogue, active module, JobDomain when the authority is the role) :
 *   - company_redundant : `required_override` on top of a Platform obligation → cleared
 *   - role_redundant    : a role's `field_config.required` on top of a Platform/company obligation → cleared
 * Everything else is PRESERVED and reported (never converted, never spread to positions) :
 *   - company_global     : a genuine company-wide obligation (stays, edited in Company › Catalogue)
 *   - company_dormant    : required on a field the company no longer proposes (ambiguous)
 *   - conflict_hidden    : required (company/platform) while a role hides the field (ambiguous : the form drops it)
 *   - role_historical    : per-role obligation (operational in a `role` company ; ambiguous in a `position` company,
 *                          where only an explicit position requirement should decide — never converted automatically)
 * apply() runs in ONE transaction, proves that the required set of every account is identical before/after (else
 * rolls back) and returns a backup that rollback() restores exactly.
 */
final class FieldRuleMigrationPlanner
{
    public const ACTIONABLE = ['company_redundant', 'role_redundant'];

    /** @return array{company_id: int, authority: string, findings: list<array>, counts: array<string, int>} */
    public static function plan(Company $company): array
    {
        $authority = RequirementAuthority::of($company->id);
        $sources = FieldObligationSources::forCompany($company);
        $findings = [];
        foreach ($sources as $code => $o) {
            $platform = $o['platform'] || $o['modules'] || $o['jobdomain'];
            if ($o['company']) {
                $findings[] = ['code' => $code, 'class' => $platform ? 'company_redundant' : 'company_global', 'detail' => $platform ? self::why($o) : null];
            }
            if ($o['company_dormant']) {
                $findings[] = ['code' => $code, 'class' => 'company_dormant', 'detail' => null];
            }
            if (($o['company'] || $platform) && $o['hidden_for_roles']) {
                $findings[] = ['code' => $code, 'class' => 'conflict_hidden', 'detail' => ['required_by' => self::why($o) ?: ['company'], 'hidden_for' => $o['hidden_for_roles']]];
            }
            foreach ($o['roles'] as $role) {
                $redundant = $platform || $o['company'];
                $findings[] = ['code' => $code, 'class' => $redundant ? 'role_redundant' : 'role_historical', 'role' => $role,
                    'detail' => $redundant ? (self::why($o) ?: ['company']) : ['authority' => $authority, 'ambiguous' => $authority === RequirementAuthority::POSITION]];
            }
        }
        $counts = array_count_values(array_column($findings, 'class'));
        ksort($counts);

        return ['company_id' => $company->id, 'authority' => $authority, 'findings' => $findings, 'counts' => $counts];
    }

    /**
     * Apply the actionable part of the plan. $dryRun : everything is done and compared, then rolled back.
     *
     * @return array{changed: int, backup: array, before: array, after: array, identical: bool, applied: bool}
     */
    public static function apply(Company $company, bool $dryRun = true): array
    {
        $plan = self::plan($company);
        $before = self::requiredSets($company);
        $backup = ['company_id' => $company->id, 'activations' => [], 'roles' => []];
        $changed = 0;

        DB::beginTransaction();
        try {
            $codes = collect($plan['findings'])->where('class', 'company_redundant')->pluck('code')->all();
            if ($codes) {
                $ids = FieldDefinition::whereIn('code', $codes)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->pluck('id');
                foreach (FieldActivation::where('company_id', $company->id)->whereIn('field_definition_id', $ids)->where('required_override', true)->get() as $a) {
                    $backup['activations'][] = ['id' => $a->id, 'required_override' => true];
                    $a->update(['required_override' => false]);
                    $changed++;
                }
            }
            $roleCodes = collect($plan['findings'])->where('class', 'role_redundant')->groupBy('role')->map(fn ($g) => $g->pluck('code')->all());
            foreach (CompanyRole::where('company_id', $company->id)->whereIn('name', $roleCodes->keys())->get() as $role) {
                $config = (array) $role->field_config;
                $backup['roles'][] = ['id' => $role->id, 'field_config' => $config];
                foreach ($config as $i => $entry) {
                    if (is_array($entry) && in_array($entry['code'] ?? null, $roleCodes[$role->name], true) && ! empty($entry['required'])) {
                        unset($config[$i]['required']);
                        $changed++;
                    }
                }
                $role->update(['field_config' => array_values($config)]);
            }
            MandatoryContext::flush();
            $after = self::requiredSets($company);
            $identical = $before === $after;
            if ($dryRun || ! $identical) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            MandatoryContext::flush();
        }

        return ['changed' => $changed, 'backup' => $backup, 'before' => $before, 'after' => $after, 'identical' => $identical, 'applied' => ! $dryRun && $identical];
    }

    /** Restore exactly what apply() changed (backup produced by apply). */
    public static function rollback(array $backup): int
    {
        $n = 0;
        DB::transaction(function () use ($backup, &$n) {
            foreach ($backup['activations'] ?? [] as $a) {
                $n += FieldActivation::where('id', $a['id'])->where('company_id', $backup['company_id'])->update(['required_override' => $a['required_override']]);
            }
            foreach ($backup['roles'] ?? [] as $r) {
                $role = CompanyRole::where('id', $r['id'])->where('company_id', $backup['company_id'])->first();
                if ($role) {
                    $role->update(['field_config' => $r['field_config']]);
                    $n++;
                }
            }
        });
        MandatoryContext::flush();

        return $n;
    }

    /**
     * The behaviour to preserve : for every account of the company, the sorted list of fields its form requires and
     * the ones still missing (a historical file must stay exactly as valid as before).
     *
     * @return array<int, array{required: list<string>, missing: list<string>}> keyed by membership id
     */
    public static function requiredSets(Company $company): array
    {
        $out = [];
        foreach (Membership::where('company_id', $company->id)->with(['user', 'companyRole'])->orderBy('id')->get() as $m) {
            if (! $m->user) {
                continue;
            }
            $fields = FieldResolverService::resolve($m->user, FieldDefinition::SCOPE_COMPANY_USER, $company->id, $m->companyRole?->key);
            $required = collect($fields)->where('required', true);
            $out[$m->id] = [
                'required' => $required->pluck('code')->sort()->values()->all(),
                'missing' => $required->filter(fn ($f) => $f['value'] === null || $f['value'] === '')->pluck('code')->sort()->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * ADR-859 (L6) — what the historical per-role rules really are, from their real usage (read only, never converted) :
     *   visibility         : `visible:false` — decides who SEES a field (a software-access concern, stays on the role)
     *   role_data          : `required` on a role whose members hold several positions (or none) — data the ROLE needs
     *   position_candidate : `required` on a role whose members all hold ONE position — may be a need of that position ;
     *                        AMBIGUOUS : shown with its impact, converted only by an explicit company decision
     *   unused             : rule on a role nobody holds
     *
     * @return list<array{role: string, role_key: string, members: int, positions: array<string, int>, rules: list<array{code: string, kind: string, class: string}>}>
     */
    public static function roleRules(Company $company): array
    {
        $out = [];
        $roles = CompanyRole::where('company_id', $company->id)->whereNotNull('field_config')->get(['id', 'key', 'name', 'field_config']);
        foreach ($roles as $role) {
            $config = array_values(array_filter((array) $role->field_config, fn ($e) => is_array($e) && ! empty($e['code'])
                && (($e['visible'] ?? true) === false || ! empty($e['required']))));
            if ($config === []) {
                continue;
            }
            $userIds = Membership::where('company_id', $company->id)->where('company_role_id', $role->id)->pluck('user_id');
            $positions = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('user_id', $userIds)
                ->with('jobRole:id,title')->get()->groupBy(fn ($e) => $e->jobRole?->title ?? '—')->map->count()->all();
            $withPosition = array_diff_key($positions, ['—' => 0]);
            $rules = [];
            foreach ($config as $e) {
                $hidden = ($e['visible'] ?? true) === false;
                $class = match (true) {
                    $userIds->isEmpty() => 'unused',
                    $hidden => 'visibility',
                    count($withPosition) === 1 && ! isset($positions['—']) => 'position_candidate',
                    default => 'role_data',
                };
                $rules[] = ['code' => $e['code'], 'kind' => $hidden ? 'hidden' : 'required', 'class' => $class];
            }
            $out[] = ['role' => $role->name, 'role_key' => $role->key, 'members' => $userIds->count(), 'positions' => $positions, 'rules' => $rules];
        }

        return $out;
    }

    /**
     * ADR-860 (L7) — module-wide obligations vs the function really exercised (read only, nothing changed).
     * A field required by an ACTIVE module (`required_by_modules`) is required from everyone today ; when the same
     * definition also carries a function tag (`tags`, e.g. `driving`), the function-based scope already exists :
     * people whose access role carries that tag (`required_tags`) or whose position explicitly requires the field.
     *
     * @return list<array{code: string, modules: list<string>, tags: list<string>, everyone: int, by_function: int, released: int}>
     */
    public static function moduleWideObligations(Company $company): array
    {
        // ADR-861 : simulates the FUNCTION scope (FunctionActivity) person by person — read only. A field stays required
        // for a person when the company asked it explicitly (everyone / that role) or the person exercises the activity.
        $sources = FieldObligationSources::forCompany($company);
        $defs = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)->whereNull('company_id')->get()->keyBy('code');
        $employees = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', '!=', 'terminated')->get(['id', 'user_id', 'job_role_id', 'metadata']);
        $members = Membership::where('company_id', $company->id)->with('companyRole:id,key,required_tags')->get()->keyBy('user_id');

        return FunctionActivity::simulate($company->id, function () use ($company, $sources, $defs, $employees, $members) {
            $out = [];
            foreach ($sources as $code => $o) {
                if (! $o['modules'] || ! isset($defs[$code])) {
                    continue;
                }
                $tags = (array) ($defs[$code]->validation_rules['tags'] ?? []);
                // ADR-862 : 'person' = declared on the person's file ; module evidence is no source, it counts in `to_declare`
                $kept = ['company' => 0, 'role_rule' => 0, 'position' => 0, 'person' => 0, 'role' => 0];
                $toDeclare = 0;
                $people = ['without_account' => ['kept' => 0, 'released' => 0], 'with_account' => ['kept' => 0, 'released' => 0]];
                $released = 0;
                foreach ($employees as $e) {
                    $role = $e->user_id ? $members->get($e->user_id)?->companyRole : null;
                    $reasons = [];
                    if ($o['platform'] || $o['company']) {
                        $reasons[] = 'company';
                    } elseif ($role && in_array($role->key, $o['role_keys'] ?? [], true)) {
                        $reasons[] = 'role_rule';
                    } else {
                        foreach ($tags as $tag) {
                            $reasons = array_merge($reasons, FunctionActivity::reasons($company->id, $e->user_id ? (int) $e->user_id : null, $e->job_role_id ? (int) $e->job_role_id : null, $tag, (array) ($role?->required_tags ?? []), (int) $e->id));
                        }
                    }
                    $bucket = $e->user_id ? 'with_account' : 'without_account';
                    if ($reasons) {
                        $kept[$reasons[0]]++;
                        $people[$bucket]['kept']++;
                    } else {
                        $released++;
                        $people[$bucket]['released']++;
                        // a module hints at the activity (e.g. recent deliveries) : someone should declare it, nobody is forced
                        if ($e->user_id && array_filter($tags, fn ($tag) => FunctionActivity::hinted($company->id, (int) $e->user_id, $tag))) {
                            $toDeclare++;
                        }
                    }
                }
                // positions the company should review BEFORE switching : a holder already drives (role / deliveries) but the
                // position does not declare it — never declared automatically (a title is not a proof)
                $review = [];
                foreach ($employees->groupBy('job_role_id') as $jobRoleId => $holders) {
                    if (! $jobRoleId) {
                        continue;
                    }
                    $driving = $holders->filter(fn ($e) => $e->user_id && (array_intersect($tags, FunctionActivity::tagsForUser($company->id, (int) $e->user_id, (array) ($members->get($e->user_id)?->companyRole?->required_tags ?? [])))
                        || array_filter($tags, fn ($tag) => FunctionActivity::hinted($company->id, (int) $e->user_id, $tag))))->count();
                    $declared = (bool) array_intersect($tags, FunctionActivity::tagsForEmployee($company->id, $holders->first()->replicate()->forceFill(['user_id' => null, 'metadata' => null])));
                    if ($driving && ! $declared) {
                        $review[] = ['job_role_id' => (int) $jobRoleId, 'holders' => $holders->count(), 'holders_without_account' => $holders->whereNull('user_id')->count(), 'holders_with_signal' => $driving];
                    }
                }
                $out[] = ['code' => $code, 'modules' => $o['modules'], 'tags' => $tags, 'everyone' => $employees->count(), 'positions_to_review' => $review,
                    'by_function' => $employees->count() - $released, 'kept_by' => $kept, 'released' => $released, 'to_declare' => $toDeclare,
                    'newly_required' => 0, // narrowing only : nobody becomes required who was not before
                    'people' => $people];
            }

            return $out;
        });
    }

    private static function why(array $o): array
    {
        return array_values(array_filter([
            $o['platform'] ? 'platform' : null,
            $o['modules'] ? 'module:'.implode(',', $o['modules']) : null,
            $o['jobdomain'] ? 'jobdomain' : null,
        ]));
    }
}
