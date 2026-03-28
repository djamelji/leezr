<?php

namespace App\Console\Commands;

use App\Core\Automation\AutomationRule;
use App\Core\Automation\AutomationRunner;
use Illuminate\Console\Command;

/**
 * ADR-425: Centralized automation runner.
 *
 * Runs all due automation rules, or a specific one via --rule=key.
 * Supports --dry-run to preview what would execute.
 */
class AutomationRunCommand extends Command
{
    protected $signature = 'automation:run
        {--rule= : Run a specific rule by key}
        {--dry-run : Show what would run without executing}';

    protected $description = 'Run all due automation rules (Automation Center)';

    public function handle(AutomationRunner $runner): int
    {
        $ruleKey = $this->option('rule');
        $dryRun = $this->option('dry-run');

        if ($ruleKey) {
            return $this->runSingle($runner, $ruleKey, $dryRun);
        }

        return $this->runAll($runner, $dryRun);
    }

    private function runSingle(AutomationRunner $runner, string $key, bool $dryRun): int
    {
        $rule = AutomationRule::where('key', $key)->first();

        if (! $rule) {
            $this->error("Rule not found: {$key}");

            return 1;
        }

        if ($dryRun) {
            $due = $runner->isDue($rule) ? 'YES' : 'NO';
            $this->info("Rule: {$rule->key} — Due: {$due} — Enabled: " . ($rule->enabled ? 'YES' : 'NO'));

            return 0;
        }

        $this->info("Running: {$rule->key}...");
        $result = $runner->runSingle($rule);

        $this->outputResult($result);

        return $result['status'] === 'error' ? 1 : 0;
    }

    private function runAll(AutomationRunner $runner, bool $dryRun): int
    {
        if ($dryRun) {
            $rules = AutomationRule::active()->get();

            $this->info('Due automation rules:');
            $this->newLine();

            $rows = $rules->map(fn ($r) => [
                $r->key,
                $r->category,
                $r->schedule,
                $runner->isDue($r) ? 'DUE' : 'not due',
                $r->next_run_at?->toDateTimeString() ?? 'never',
            ])->all();

            $this->table(['Key', 'Category', 'Schedule', 'Status', 'Next Run'], $rows);

            return 0;
        }

        $this->info('Running all due automation rules...');
        $this->newLine();

        $results = $runner->runAll();

        if (empty($results)) {
            $this->info('No rules were due for execution.');

            return 0;
        }

        $hasErrors = false;

        foreach ($results as $result) {
            $this->outputResult($result);

            if ($result['status'] === 'error') {
                $hasErrors = true;
            }
        }

        $this->newLine();

        $ok = count(array_filter($results, fn ($r) => $r['status'] === 'ok'));
        $errors = count(array_filter($results, fn ($r) => $r['status'] === 'error'));
        $skipped = count(array_filter($results, fn ($r) => $r['status'] === 'skipped'));

        $this->info("Summary: {$ok} ok, {$errors} errors, {$skipped} skipped");

        return $hasErrors ? 1 : 0;
    }

    private function outputResult(array $result): void
    {
        $icon = match ($result['status']) {
            'ok' => '✓',
            'error' => '✗',
            'skipped' => '⊘',
        };

        $method = $result['status'] === 'error' ? 'error' : 'info';

        $this->{$method}("{$icon} {$result['key']} — {$result['status']} ({$result['duration_ms']}ms, {$result['actions_count']} actions)");

        if ($result['error']) {
            $this->warn("  Error: {$result['error']}");
        }
    }
}
