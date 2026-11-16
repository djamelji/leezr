<?php

namespace App\Core\Jobdomains\Modernization;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Presets\PresetService;
use App\Core\Requirements\RequirementParityComparator;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Requirements\RequirementConfigService;
use Illuminate\Support\Facades\DB;

/**
 * ADR-797 (L7) — READ-ONLY compatibility of ONE existing company with the versioned model of its
 * JobDomain. Assembles existing instruments, never re-computes them :
 *  - recommended presets : each family adapter's own preview (identical / customized / conflict /
 *    requires_confirmation / new) ;
 *  - requirements : RequirementParityComparator (historical DocumentResolverService vs parallel resolver) ;
 *  - historical role prescriptions → positions : RequirementConfigService::legacyMapping ;
 *  - modules : activation flag vs real activation authority (ModuleGate) ;
 *  - people : records without position, without account, preparatory, multi-company accounts.
 * Classes : certain · partial · ambiguous · conflict · human_decision. Nothing is converted, reconciled or
 * applied ; no position is deduced from an RBAC role ; no employee is modified.
 */
final class CompanyJobdomainCompatibility
{
    private const PRESET_CLASS = ['identical' => 'certain', 'customized' => 'partial', 'conflict' => 'conflict',
        'requires_confirmation' => 'human_decision', 'new' => 'proposal', 'not_applicable' => 'not_applicable'];

    public static function report(Company $company): array
    {
        $presets = [];
        foreach (PresetService::recommendationsFor((string) $company->jobdomain_key, $company->market_key) as $rec) {
            if (! $rec['usable']) {
                $presets[] = ['kind' => $rec['kind'], 'family_key' => $rec['family_key'], 'version' => null, 'counts' => [], 'note' => 'no_published_version'];

                continue;
            }
            $preview = PresetService::preview($company, PresetService::find($rec['kind'], $rec['effective_id']));
            $classes = array_map(fn ($i) => self::PRESET_CLASS[$i['status']] ?? 'ambiguous', $preview['items']);
            $presets[] = ['kind' => $rec['kind'], 'family_key' => $rec['family_key'], 'version' => $rec['effective_version'],
                'last_applied_version' => $preview['last_applied_version'], 'missing_modules' => $preview['missing_modules'],
                'counts' => array_count_values($classes), 'items' => array_map(fn ($i, $c) => ['key' => $i['key'], 'label' => $i['label'], 'class' => $c, 'detail' => $i['detail']], $preview['items'], $classes)];
        }

        $parity = RequirementParityComparator::compare($company);
        $mapping = collect((new RequirementConfigService($company))->legacyMapping());

        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', '!=', Employee::STATUS_TERMINATED)->get(['id', 'user_id', 'job_role_id', 'status', 'preparatory_at']);
        $userIds = $employees->pluck('user_id')->filter();
        $multi = $userIds->isEmpty() ? 0 : DB::table('memberships')->whereIn('user_id', $userIds)->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->get()->count();

        $flags = DB::table('company_modules')->where('company_id', $company->id)->get(['module_key', 'is_enabled_for_company'])->keyBy('module_key');
        $modules = ['active' => 0, 'inactive' => 0, 'ambiguous' => []];
        foreach ($flags as $key => $f) {
            $active = ModuleGate::isActive($company, $key);
            if ($f->is_enabled_for_company && ! $active) {
                $modules['ambiguous'][] = $key; // drapeau historique sans activation réelle
            } else {
                $active ? $modules['active']++ : $modules['inactive']++;
            }
        }

        $summary = ['certain' => 0, 'partial' => 0, 'ambiguous' => 0, 'conflict' => 0, 'human_decision' => 0];
        foreach ($presets as $p) {
            foreach (array_intersect_key($p['counts'], $summary) as $c => $n) {
                $summary[$c] += $n;
            }
        }
        $summary['ambiguous'] += ($parity['by_category'][RequirementParityComparator::AMBIGUOUS] ?? 0) + count($modules['ambiguous']) + $mapping->where('category', 'ambiguous')->count();
        $summary['conflict'] += $mapping->where('category', 'conflict')->count();
        $summary['human_decision'] += ($parity['by_category'][RequirementParityComparator::ARBITRATION] ?? 0) + ($parity['by_category'][RequirementParityComparator::MISSING] ?? 0)
            + $mapping->where('category', 'needs_decision')->count();
        $summary['certain'] += $mapping->where('category', 'certain')->count();
        $summary['partial'] += $mapping->where('category', 'proposed')->count();

        return [
            'company' => ['id' => $company->id, 'name' => $company->name, 'jobdomain_key' => $company->jobdomain_key, 'market_key' => $company->market_key],
            'summary' => $summary,
            'presets' => $presets,
            'requirements' => ['authority' => 'DocumentResolverService (historique)', 'parallel' => 'EffectiveRequirementResolver (non opérationnel)',
                'historical' => $parity['requirements']['historical'], 'parallel_required' => $parity['requirements']['parallel'], 'matches' => $parity['matches'],
                'differences' => $parity['differences'], 'by_category' => $parity['by_category'], 'subjects_without_configuration' => $parity['subjects_without_configuration'],
                'conflicting_sources' => $parity['conflicting_sources']],
            'role_prescriptions' => ['by_category' => $mapping->countBy('category')->all(), 'items' => $mapping->values()->all()],
            'modules' => $modules,
            'people' => ['records' => $employees->count(), 'without_position' => $employees->whereNull('job_role_id')->count(),
                'without_account' => $employees->whereNull('user_id')->count(), 'preparatory' => $employees->filter(fn ($e) => $e->isPreparatory())->count(),
                'multi_company_accounts' => $multi,
                // Couples poste × rôle RBAC observés : soumis à validation humaine, jamais corrigés ni déduits.
                'position_role_pairs' => self::positionRolePairs($company, $employees)],
            'guarantees' => ['read_only' => true, 'no_position_deduced_from_role' => true, 'no_reconciliation' => true],
        ];
    }

    private static function positionRolePairs(Company $company, $employees): array
    {
        $roles = DB::table('memberships')->leftJoin('company_roles', 'company_roles.id', '=', 'memberships.company_role_id')
            ->where('memberships.company_id', $company->id)->pluck('company_roles.name', 'memberships.user_id');
        $jobs = \App\Core\Workforce\JobRole::withoutGlobalScopes()->where('company_id', $company->id)->pluck('title', 'id');

        return $employees->whereNotNull('user_id')->whereNotNull('job_role_id')
            ->groupBy(fn ($e) => ($jobs[$e->job_role_id] ?? '?').' × '.($roles[$e->user_id] ?? '—'))
            ->map(fn ($g, $pair) => ['pair' => $pair, 'count' => $g->count()])->values()->sortByDesc('count')->values()->all();
    }
}
