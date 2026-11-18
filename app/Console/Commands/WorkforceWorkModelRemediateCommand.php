<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-826 — CONTROLLED remediation of contracts whose `work_model_key` is outside EmploymentContract::WORK_MODELS.
 *
 *  - Diagnostic by default (nothing written) : every such contract, with its company, employee, status, and
 *    a hint when the value belongs to another vocabulary (on_site / hybrid / remote = the « work mode »
 *    field, a PLACE of work, not a working-time model).
 *  - No value is ever guessed : a correction needs an explicit --contract=<id> AND --set=<model>, plus
 *    --apply and --confirm-database=<current database>. One transaction per contract, audited
 *    (`contract.work_model_remediated`, before / after, batch id).
 *  - --restore=<batch> puts back exactly the previous values of that batch (simulation unless --apply).
 */
class WorkforceWorkModelRemediateCommand extends Command
{
    protected $signature = 'workforce:work-model-remediate
        {--company=* : limit to these company ids}
        {--contract=* : contract ids to correct (required with --set)}
        {--set= : the work model to write (one of the allowed models)}
        {--apply : write the changes}
        {--confirm-database= : required with --apply : the current database name}
        {--restore= : batch id to restore}';

    protected $description = 'Diagnose / correct contracts with an unknown work model (diagnostic unless --apply) — ADR-826';

    private const WORK_MODE_VALUES = ['on_site', 'hybrid', 'remote'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $db = DB::connection()->getDatabaseName();
        if ($apply && $this->option('confirm-database') !== $db) {
            $this->error("Refused : --apply requires --confirm-database={$db}.");

            return self::FAILURE;
        }
        if ($this->option('restore')) {
            return $this->restore((string) $this->option('restore'), $apply, $db);
        }
        $set = $this->option('set');
        $targets = array_map('intval', (array) $this->option('contract'));
        if ($set !== null && (! in_array($set, EmploymentContract::WORK_MODELS, true) || $targets === [])) {
            $this->error('Refused : --set must be one of '.implode(', ', EmploymentContract::WORK_MODELS).' and needs explicit --contract ids.');

            return self::FAILURE;
        }

        $rows = EmploymentContract::withoutGlobalScopes()->whereNotIn('work_model_key', EmploymentContract::WORK_MODELS)
            ->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->orderBy('company_id')->orderBy('id')->get();
        $batch = $apply && $set ? 'wm-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)) : null;
        $out = [];
        foreach ($rows as $c) {
            $row = ['company_id' => $c->company_id, 'contract_id' => $c->id, 'employee_id' => $c->employee_id, 'contract_type' => $c->contract_type,
                'status' => $c->status, 'is_current' => (bool) $c->is_current, 'work_model_key' => $c->work_model_key,
                'hint' => in_array($c->work_model_key, self::WORK_MODE_VALUES, true)
                    ? 'value of the « work mode » field (place of work), not a working-time model : the real model must be confirmed by the company'
                    : 'unknown value : the real model must be confirmed by the company'];
            $targeted = $set && in_array($c->id, $targets, true);
            $row['action'] = ! $targeted ? 'diagnostic_only' : ($apply ? 'corrected' : 'would_correct');
            if ($targeted) {
                $row['set'] = $set;
            }
            if ($apply && $targeted) {
                DB::transaction(function () use ($c, $set, $batch) {
                    $before = $c->work_model_key;
                    EmploymentContract::withoutGlobalScopes()->whereKey($c->id)->update(['work_model_key' => $set]);
                    app(AuditLogger::class)->logCompany($c->company_id, 'contract.work_model_remediated', 'employment_contract', (string) $c->id, [
                        'actorType' => 'system', 'actorId' => null, 'severity' => 'warning',
                        'diffBefore' => ['work_model_key' => $before], 'diffAfter' => ['work_model_key' => $set],
                        'metadata' => ['batch' => $batch, 'source' => 'workforce:work-model-remediate (ADR-826)'],
                    ]);
                });
            }
            $out[] = $row;
        }
        $this->line(json_encode(['database' => $db, 'mode' => $apply ? 'apply' : 'diagnostic', 'batch' => $batch,
            'summary' => collect($out)->countBy('action')->all(), 'contracts' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function restore(string $batch, bool $apply, string $db): int
    {
        $logs = DB::table('company_audit_logs')->where('action', 'contract.work_model_remediated')->get()
            ->filter(fn ($l) => (json_decode((string) $l->metadata, true)['batch'] ?? null) === $batch);
        $out = [];
        foreach ($logs as $log) {
            $previous = json_decode((string) $log->diff_before, true)['work_model_key'] ?? null;
            $c = EmploymentContract::withoutGlobalScopes()->find((int) $log->target_id);
            if (! $c || $previous === null) {
                $out[] = ['contract_id' => (int) $log->target_id, 'action' => 'not_restorable'];

                continue;
            }
            $row = ['company_id' => $c->company_id, 'contract_id' => $c->id, 'from' => $c->work_model_key, 'to' => $previous,
                'action' => $c->work_model_key === $previous ? 'already_restored' : ($apply ? 'restored' : 'would_restore')];
            if ($apply && $row['action'] === 'restored') {
                DB::transaction(function () use ($c, $previous, $batch) {
                    $current = $c->work_model_key;
                    EmploymentContract::withoutGlobalScopes()->whereKey($c->id)->update(['work_model_key' => $previous]);
                    app(AuditLogger::class)->logCompany($c->company_id, 'contract.work_model_restored', 'employment_contract', (string) $c->id, [
                        'actorType' => 'system', 'actorId' => null, 'severity' => 'warning',
                        'diffBefore' => ['work_model_key' => $current], 'diffAfter' => ['work_model_key' => $previous],
                        'metadata' => ['batch' => $batch, 'source' => 'workforce:work-model-remediate --restore (ADR-826)'],
                    ]);
                });
            }
            $out[] = $row;
        }
        $this->line(json_encode(['database' => $db, 'mode' => $apply ? 'restore' : 'restore-simulation', 'batch' => $batch,
            'summary' => collect($out)->countBy('action')->all(), 'contracts' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
