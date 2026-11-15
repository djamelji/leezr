<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-793 (L3) — READ-ONLY data-quality report of employee POSITIONS, for HR to fix them before any
 * operational switch (ADR-791 / 792). Nothing is written ; nothing is assigned.
 *
 * Signals : position missing · position suspicious (with the reason) · person in several companies ·
 * employee without account · historical role rules that could map to the position · human validation.
 * The RBAC role is used as a CLUE only (e.g. a field-worker access role on a manager-level position),
 * never as a source of assignment.
 *
 *   php artisan jobroles:quality-report --company=1 [--details]
 */
class JobRoleQualityReportCommand extends Command
{
    protected $signature = 'jobroles:quality-report {--company=* : company ids (default: all with employees)} {--details : list every flagged employee}';

    protected $description = 'Read-only data-quality report of employee positions (ADR-793)';

    public function handle(): int
    {
        $companies = Company::withoutGlobalScopes()->when($this->option('company'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->whereIn('id', Employee::withoutGlobalScopes()->select('company_id'))->orderBy('id')->get();
        $multi = Employee::withoutGlobalScopes()->whereNotNull('user_id')->where('status', '!=', 'terminated')
            ->select('user_id', DB::raw('count(distinct company_id) as c'))->groupBy('user_id')->having('c', '>', 1)->pluck('c', 'user_id');

        $out = [];
        foreach ($companies as $company) {
            $jobs = JobRole::withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('id');
            $roles = DB::table('memberships')->leftJoin('company_roles', 'company_roles.id', '=', 'memberships.company_role_id')
                ->where('memberships.company_id', $company->id)
                ->get(['memberships.user_id', 'company_roles.name', 'company_roles.archetype', 'company_roles.is_administrative', 'company_roles.doc_config', 'company_roles.field_config'])->keyBy('user_id');
            $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', '!=', 'terminated')->get(['id', 'user_id', 'job_role_id', 'first_name', 'last_name']);

            $report = ['company_id' => $company->id, 'employees' => $employees->count(), 'with_position' => 0, 'missing_position' => 0, 'suspicious_position' => 0,
                'accountless' => 0, 'multi_company' => 0, 'historical_rules_available' => 0, 'needs_human_validation' => 0, 'positions' => [], 'details' => []];
            foreach ($employees as $e) {
                $role = $e->user_id ? ($roles[$e->user_id] ?? null) : null;
                $job = $e->job_role_id ? $jobs->get($e->job_role_id) : null;
                $flags = [];
                $job ? $report['with_position']++ : $flags[] = 'missing_position';
                $e->user_id === null && $flags[] = 'accountless';
                $e->user_id && isset($multi[$e->user_id]) && $flags[] = 'multi_company';
                if ($job && $role && $reason = $this->suspicion($job, $role)) {
                    $flags[] = 'suspicious_position:'.$reason;
                }
                if ($role && (($role->doc_config && $role->doc_config !== 'null') || ($role->field_config && $role->field_config !== 'null'))) {
                    $flags[] = 'historical_rules:'.$role->name;
                }
                foreach ($flags as $f) {
                    $k = explode(':', $f)[0];
                    $report[$k === 'missing_position' ? 'missing_position' : ($k === 'suspicious_position' ? 'suspicious_position' : ($k === 'historical_rules' ? 'historical_rules_available' : ($k === 'multi_company' ? 'multi_company' : 'accountless')))]++;
                }
                $needsHuman = in_array('missing_position', $flags, true) || collect($flags)->contains(fn ($f) => str_starts_with($f, 'suspicious_position'));
                $needsHuman && $report['needs_human_validation']++;
                if ($job) {
                    $report['positions'][$job->title] = ($report['positions'][$job->title] ?? 0) + 1;
                }
                if ($this->option('details') && $flags) {
                    $report['details'][] = ['employee_id' => $e->id, 'name' => trim($e->first_name.' '.$e->last_name), 'position' => $job?->title, 'access_role' => $role?->name, 'flags' => $flags, 'needs_human_validation' => $needsHuman];
                }
            }
            $out[] = $report;
        }
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** Clue only : the access role's archetype contradicts the position's level. */
    private function suspicion(JobRole $job, object $role): ?string
    {
        $level = $job->level; // agent | manager | …
        return match (true) {
            $role->archetype === 'field_worker' && in_array($level, ['manager', 'director', 'head'], true) => "rôle d'accès terrain mais poste de niveau {$level}",
            $role->archetype === 'management' && $level === 'agent' && ! $role->is_administrative => "rôle d'accès de direction mais poste de niveau agent",
            default => null,
        };
    }
}
