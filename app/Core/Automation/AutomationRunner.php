<?php

namespace App\Core\Automation;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AutomationRunner
{
    /**
     * Maps automation rule keys to artisan commands.
     */
    private const COMMAND_MAP = [
        'documents.auto_remind' => 'documents:auto-remind',
        'documents.auto_renew' => 'documents:auto-renew',
        'documents.check_expiration' => 'documents:check-expiration',
        'billing.retry_payment' => 'billing:process-dunning',
        'billing.renew_subscriptions' => 'billing:renew',
        'billing.recover_webhooks' => 'billing:recover-webhooks',
        'billing.recover_checkouts' => 'billing:recover-checkouts',
        'billing.check_expiring_cards' => 'billing:check-expiring-cards',
        'billing.check_trial_expiring' => 'billing:check-trial-expiring',
        'billing.expire_trials' => 'billing:expire-trials',
        'billing.collect_scheduled' => 'billing:collect-scheduled',
        'billing.check_dlq' => 'billing:check-dlq',
        'billing.reconcile' => 'billing:reconcile',
        'fx.refresh_rates' => 'fx:refresh',
    ];

    /**
     * Run all active rules that are due.
     *
     * @return array Summary of runs [{key, status, duration_ms, actions_count, error}]
     */
    public function runAll(): array
    {
        $rules = AutomationRule::active()
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                  ->orWhere('next_run_at', '<=', now());
            })
            ->get();

        $results = [];

        foreach ($rules as $rule) {
            $results[] = $this->runSingle($rule);
        }

        return $results;
    }

    /**
     * Run a single automation rule.
     *
     * @return array {key, status, duration_ms, actions_count, error}
     */
    public function runSingle(AutomationRule $rule): array
    {
        $command = self::COMMAND_MAP[$rule->key] ?? null;

        if (! $command) {
            $this->recordRun($rule, 'skipped', 0, 0, "No command mapped for key: {$rule->key}");

            return [
                'key' => $rule->key,
                'status' => 'skipped',
                'duration_ms' => 0,
                'actions_count' => 0,
                'error' => "No command mapped for key: {$rule->key}",
            ];
        }

        $start = microtime(true);

        try {
            $exitCode = Artisan::call($command);
            $output = trim(Artisan::output());

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $actionsCount = $this->parseActionsCount($output);
            $status = $exitCode === 0 ? 'ok' : 'error';
            $error = $exitCode !== 0 ? "Exit code: {$exitCode}. Output: {$output}" : null;

            $this->recordRun($rule, $status, $durationMs, $actionsCount, $error, ['output' => $output]);

            return [
                'key' => $rule->key,
                'status' => $status,
                'duration_ms' => $durationMs,
                'actions_count' => $actionsCount,
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::error("[AutomationRunner] Error running {$rule->key}: {$e->getMessage()}", [
                'rule' => $rule->key,
                'exception' => $e,
            ]);

            $this->recordRun($rule, 'error', $durationMs, 0, $e->getMessage());

            return [
                'key' => $rule->key,
                'status' => 'error',
                'duration_ms' => $durationMs,
                'actions_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a rule is currently due for execution.
     */
    public function isDue(AutomationRule $rule): bool
    {
        if (! $rule->enabled) {
            return false;
        }

        if ($rule->next_run_at === null) {
            return true;
        }

        return $rule->next_run_at->lte(now());
    }

    /**
     * Calculate the next run time based on the schedule expression.
     */
    public function calculateNextRun(string $schedule): Carbon
    {
        $cronExpr = $this->normalizeCronExpression($schedule);
        $cron = new CronExpression($cronExpr);

        return Carbon::instance($cron->getNextRunDate());
    }

    /**
     * Record a run: update rule + create log entry.
     */
    private function recordRun(
        AutomationRule $rule,
        string $status,
        int $durationMs,
        int $actionsCount,
        ?string $error = null,
        ?array $metadata = null,
    ): void {
        $nextRunAt = $this->calculateNextRun($rule->schedule);

        $rule->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRunAt,
            'last_status' => $status,
            'last_error' => $error,
            'last_run_duration_ms' => $durationMs,
            'last_run_actions' => $actionsCount,
        ]);

        AutomationRunLog::create([
            'automation_rule_id' => $rule->id,
            'status' => $status,
            'actions_count' => $actionsCount,
            'duration_ms' => $durationMs,
            'error' => $error,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Normalize schedule keywords to cron expressions.
     */
    private function normalizeCronExpression(string $schedule): string
    {
        return match ($schedule) {
            'daily' => '0 0 * * *',
            'hourly' => '0 * * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
            default => $schedule,
        };
    }

    /**
     * Try to extract an actions count from command output.
     * Looks for patterns like "Processed 5 items" or "3 actions".
     */
    private function parseActionsCount(string $output): int
    {
        if (preg_match('/(\d+)\s+(items?|actions?|processed|sent|renewed|reminded|entries)/i', $output, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
