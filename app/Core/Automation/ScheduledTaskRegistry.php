<?php

namespace App\Core\Automation;

use Carbon\Carbon;
use Cron\CronExpression;

class ScheduledTaskRegistry
{
    /**
     * Static registry of all 14 scheduled tasks with metadata.
     * Keys match the task identifier used in SchedulerInstrumentation.
     */
    private const TASKS = [
        // ── Billing ──────────────────────────────────────────
        'billing:expire-trials' => [
            'command' => 'billing:expire-trials',
            'description' => 'automations.tasks.expireTrials',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'billing:renew' => [
            'command' => 'billing:renew',
            'description' => 'automations.tasks.renew',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'billing:process-dunning' => [
            'command' => 'billing:process-dunning',
            'description' => 'automations.tasks.processDunning',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'billing:reconcile' => [
            'command' => 'billing:reconcile',
            'description' => 'automations.tasks.reconcile',
            'category' => 'billing',
            'frequency' => 'weekly',
            'cron' => '0 0 * * 0',
            'expected_interval_minutes' => 10080,
        ],
        'billing:recover-webhooks' => [
            'command' => 'billing:recover-webhooks',
            'description' => 'automations.tasks.recoverWebhooks',
            'category' => 'billing',
            'frequency' => 'every10min',
            'cron' => '*/10 * * * *',
            'expected_interval_minutes' => 10,
        ],
        'billing:recover-checkouts' => [
            'command' => 'billing:recover-checkouts',
            'description' => 'automations.tasks.recoverCheckouts',
            'category' => 'billing',
            'frequency' => 'every10min',
            'cron' => '*/10 * * * *',
            'expected_interval_minutes' => 10,
        ],
        'billing:check-dlq' => [
            'command' => 'billing:check-dlq',
            'description' => 'automations.tasks.checkDlq',
            'category' => 'billing',
            'frequency' => 'hourly',
            'cron' => '0 * * * *',
            'expected_interval_minutes' => 60,
        ],
        'billing:check-expiring-cards' => [
            'command' => 'billing:check-expiring-cards',
            'description' => 'automations.tasks.checkExpiringCards',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'billing:check-trial-expiring' => [
            'command' => 'billing:check-trial-expiring',
            'description' => 'automations.tasks.checkTrialExpiring',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'billing:collect-scheduled' => [
            'command' => 'billing:collect-scheduled',
            'description' => 'automations.tasks.collectScheduled',
            'category' => 'billing',
            'frequency' => 'dailyAt06',
            'cron' => '0 6 * * *',
            'expected_interval_minutes' => 1440,
        ],

        // ── Documents ────────────────────────────────────────
        'documents:check-expiration' => [
            'command' => 'documents:check-expiration',
            'description' => 'automations.tasks.checkExpiration',
            'category' => 'documents',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'documents:auto-renew' => [
            'command' => 'documents:auto-renew',
            'description' => 'automations.tasks.autoRenew',
            'category' => 'documents',
            'frequency' => 'dailyAt08',
            'cron' => '0 8 * * *',
            'expected_interval_minutes' => 1440,
        ],
        'documents:auto-remind' => [
            'command' => 'documents:auto-remind',
            'description' => 'automations.tasks.autoRemind',
            'category' => 'documents',
            'frequency' => 'dailyAt09',
            'cron' => '0 9 * * *',
            'expected_interval_minutes' => 1440,
        ],

        // ── System ───────────────────────────────────────────
        'fx:rates-sync' => [
            'command' => 'fx:rates-sync',
            'description' => 'automations.tasks.fxRatesSync',
            'category' => 'system',
            'frequency' => 'every6h',
            'cron' => '0 */6 * * *',
            'expected_interval_minutes' => 360,
        ],

        // ── Workflow Engine (ADR-437) ───────────────────────────
        'workflow:reset-daily-counters' => [
            'command' => 'workflow:reset-daily-counters',
            'description' => 'automations.tasks.workflowResetCounters',
            'category' => 'system',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
        ],

        // ── Alert Center (ADR-438) ──────────────────────────────
        'alerts:evaluate' => [
            'command' => 'alerts:evaluate',
            'description' => 'automations.tasks.alertsEvaluate',
            'category' => 'system',
            'frequency' => 'every5min',
            'cron' => '*/5 * * * *',
            'expected_interval_minutes' => 5,
        ],

        // ── Email ────────────────────────────────────────────
        'email:fetch-inbox' => [
            'command' => 'email:fetch-inbox',
            'description' => 'automations.tasks.emailFetchInbox',
            'category' => 'system',
            'frequency' => 'every5min',
            'cron' => '*/5 * * * *',
            'expected_interval_minutes' => 5,
        ],

        // ── Usage Monitoring (P3-4) ─────────────────────────────
        'usage:collect-snapshots' => [
            'command' => 'usage:collect-snapshots',
            'description' => 'automations.tasks.usageCollectSnapshots',
            'category' => 'system',
            'frequency' => 'daily',
            'cron' => '55 23 * * *',
            'expected_interval_minutes' => 1440,
        ],
    ];

    // ── Accessors ─────────────────────────────────────────

    public static function all(): array
    {
        return self::TASKS;
    }

    public static function get(string $task): ?array
    {
        return self::TASKS[$task] ?? null;
    }

    public static function exists(string $task): bool
    {
        return isset(self::TASKS[$task]);
    }

    public static function names(): array
    {
        return array_keys(self::TASKS);
    }

    public static function command(string $task): ?string
    {
        return self::TASKS[$task]['command'] ?? null;
    }

    // ── Health computation ─────────────────────────────────

    /**
     * Compute health status for a task based on its last run.
     *
     * @return 'ok'|'delayed'|'broken'|'unknown'
     */
    public static function computeHealth(string $task, ?ScheduledTaskRun $lastRun): string
    {
        $meta = self::get($task);
        if (! $meta) {
            return 'broken';
        }

        if (! $lastRun) {
            return 'unknown';
        }

        $expectedMinutes = $meta['expected_interval_minutes'];
        $minutesSinceRun = $lastRun->started_at
            ? $lastRun->started_at->diffInMinutes(now())
            : PHP_INT_MAX;

        if ($lastRun->status === 'failed') {
            return 'broken';
        }

        if ($lastRun->status === 'running') {
            // Running for more than 10 minutes is suspicious
            return $minutesSinceRun > 10 ? 'delayed' : 'ok';
        }

        // status = success
        if ($minutesSinceRun > $expectedMinutes * 2) {
            return 'broken';
        }

        if ($minutesSinceRun > $expectedMinutes * 1.1) {
            return 'delayed';
        }

        return 'ok';
    }

    // ── Next run calculation (UPGRADE) ─────────────────────

    /**
     * Calculate next run time from cron expression.
     */
    public static function nextRunAt(string $task): ?Carbon
    {
        $meta = self::get($task);
        if (! $meta) {
            return null;
        }

        try {
            $cron = new CronExpression($meta['cron']);

            return Carbon::instance($cron->getNextRunDate());
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Global scheduler health (UPGRADE) ──────────────────

    /**
     * Detect scheduler silence: if no run started in the last N minutes,
     * the scheduler itself may be dead.
     *
     * @return array{status: 'ok'|'silent'|'dead', last_activity_at: ?string, silence_minutes: int}
     */
    public static function globalHealth(int $silenceThresholdMinutes = 2): array
    {
        $lastRun = ScheduledTaskRun::query()
            ->latest('started_at')
            ->first();

        if (! $lastRun || ! $lastRun->started_at) {
            return [
                'status' => 'dead',
                'last_activity_at' => null,
                'silence_minutes' => PHP_INT_MAX,
            ];
        }

        $silenceMinutes = (int) $lastRun->started_at->diffInMinutes(now());

        // We check against the shortest expected interval (every 10 min)
        // If silence > threshold, something is wrong
        $status = 'ok';
        if ($silenceMinutes > $silenceThresholdMinutes * 5) {
            $status = 'dead';  // 10+ minutes of silence
        } elseif ($silenceMinutes > $silenceThresholdMinutes) {
            $status = 'silent';  // 2+ minutes, could be normal gap
        }

        return [
            'status' => $status,
            'last_activity_at' => $lastRun->started_at->toIso8601String(),
            'silence_minutes' => $silenceMinutes,
        ];
    }

    // ── Queue stats ──────────────────────────────────────────

    /**
     * Gather queue statistics from jobs/failed_jobs tables.
     */
    public static function queueStats(): array
    {
        $since = now()->subDay();

        return [
            'queue_default_pending' => \DB::table('jobs')->where('queue', 'default')->count(),
            'queue_ai_pending' => \DB::table('jobs')->where('queue', 'ai')->count(),
            'queue_default_failed_24h' => \DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->where('queue', 'default')
                ->count(),
            'queue_ai_failed_24h' => \DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->where('queue', 'ai')
                ->count(),
        ];
    }

    // ── Output parsing (UPGRADE — future metrics) ──────────

    /**
     * Attempt to parse structured output from a task run.
     * Supports JSON output or falls back to raw text.
     *
     * @return array{type: 'json'|'text', data: mixed}
     */
    public static function parseOutput(?string $output): array
    {
        if (! $output || trim($output) === '') {
            return ['type' => 'text', 'data' => null];
        }

        // Try JSON first (future: commands can output {"metrics": {...}})
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['type' => 'json', 'data' => $decoded];
            }
        }

        return ['type' => 'text', 'data' => $trimmed];
    }
}
