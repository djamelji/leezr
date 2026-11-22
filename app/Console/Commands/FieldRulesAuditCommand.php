<?php

namespace App\Console\Commands;

use App\Console\Recipe\RecipeDatabaseGuard;
use App\Core\Fields\FieldRuleMigrationPlanner;
use App\Core\Models\Company;
use Illuminate\Console\Command;

/**
 * ADR-858 (Leezr 5.0 L5) — audit of the stored person-field rules and their REVERSIBLE migration.
 *
 *   fields:rules-audit [--company=*] [--out=report.json]      read only : plan + simulated migration (rolled back)
 *   fields:rules-audit --apply --out=backup.json [--company=*] writes, MARKED recipe clone only ; keeps a backup
 *   fields:rules-audit --rollback=backup.json                   restores a backup, MARKED recipe clone only
 *   fields:rules-audit --function-scope=on|off --company=*      ADR-861 activity scope of module fields, MARKED clone only
 *
 * A company is migrated only when every account keeps exactly the same required and missing fields.
 */
class FieldRulesAuditCommand extends Command
{
    protected $signature = 'fields:rules-audit {--company=* : company ids (default: all)} {--apply : apply the redundant clean-up (clone only)} {--rollback= : restore a backup file (clone only)} {--out= : JSON report / backup file} {--roles : add the per-role rules analysis (read only)} {--module-wide : simulate function-based scope of module-wide obligations (read only)} {--function-scope= : on|off — switch the activity scope of module fields (clone only, ADR-861)}';

    protected $description = 'ADR-858 — audit (and, on a recipe clone, reversibly migrate) the stored person-field rules';

    public function handle(RecipeDatabaseGuard $guard): int
    {
        $writes = $this->option('apply') || $this->option('rollback') || $this->option('function-scope');
        if ($writes) {
            $verdict = $guard->assess($this->getName());
            if (! $verdict['allowed']) {
                $this->error('Refused: writes only on a marked recipe clone. '.implode(' ', $verdict['reasons']));

                return self::FAILURE;
            }
        }

        if ($switch = $this->option('function-scope')) {
            $ids = array_map('intval', (array) $this->option('company'));
            if (! $ids || ! in_array($switch, ['on', 'off'], true)) {
                $this->error('--function-scope=on|off needs explicit --company ids');

                return self::FAILURE;
            }
            foreach ($ids as $id) {
                $previous = \App\Core\Fields\FunctionActivity::setScope($id, $switch === 'on' ? \App\Core\Fields\FunctionActivity::SCOPE_FUNCTION : \App\Core\Fields\FunctionActivity::SCOPE_EVERYONE);
                $this->info("company $id : module_obligation_scope ".($previous ?? 'null').' → '.($switch === 'on' ? 'function' : 'everyone'));
            }

            return self::SUCCESS;
        }

        if ($file = $this->option('rollback')) {
            $backups = json_decode((string) file_get_contents($file), true) ?: [];
            $n = 0;
            foreach ($backups['companies'] ?? [] as $b) {
                $n += FieldRuleMigrationPlanner::rollback($b['backup']);
            }
            $this->info("restored $n rows from $file");

            return self::SUCCESS;
        }

        $ids = array_map('intval', (array) $this->option('company'));
        $companies = Company::query()->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
        $report = ['generated_at' => now()->toIso8601String(), 'mode' => $this->option('apply') ? 'apply' : 'dry-run', 'companies' => []];
        $rows = [];
        foreach ($companies as $company) {
            $plan = FieldRuleMigrationPlanner::plan($company);
            $actionable = collect($plan['findings'])->whereIn('class', FieldRuleMigrationPlanner::ACTIONABLE)->count();
            $run = $actionable ? FieldRuleMigrationPlanner::apply($company, ! $this->option('apply')) : null;
            $persons = count($run['before'] ?? FieldRuleMigrationPlanner::requiredSets($company));
            $report['companies'][] = [
                'company_id' => $company->id, 'name' => $company->name, 'authority' => $plan['authority'], 'counts' => $plan['counts'],
                'findings' => $plan['findings'], 'accounts' => $persons,
                'migration' => $run ? ['changed' => $run['changed'], 'identical_behaviour' => $run['identical'], 'applied' => $run['applied']] : null,
                'backup' => $run && $run['applied'] ? $run['backup'] : null,
                'role_rules' => $this->option('roles') ? FieldRuleMigrationPlanner::roleRules($company) : null, // ADR-859
                'module_wide' => $this->option('module-wide') ? FieldRuleMigrationPlanner::moduleWideObligations($company) : null, // ADR-860
            ];
            $rows[] = [$company->id, mb_strimwidth($company->name, 0, 24, '…'), $plan['authority'], $persons,
                json_encode($plan['counts']), $run ? ($run['changed'].' / '.($run['identical'] ? 'identical' : 'DIFFERS')) : '—', $run && $run['applied'] ? 'yes' : 'no'];
        }
        $this->table(['id', 'company', 'authority', 'accounts', 'findings', 'redundant changed / behaviour', 'applied'], $rows);
        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("report written to $out");
        }

        return self::SUCCESS;
    }
}
