<?php

namespace App\Core\Automation;

use Carbon\Carbon;
use Cron\CronExpression;

class ScheduledTaskRegistry
{
    /**
     * Static registry of all scheduled tasks with metadata.
     * Single source of truth for the Automations cockpit; kept in sync with
     * routes/console.php by ScheduledTaskRegistryConsistencyTest (drift guard).
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
            'sensitive' => true,
        ],
        'billing:renew' => [
            'command' => 'billing:renew',
            'description' => 'automations.tasks.renew',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
            'sensitive' => true,
        ],
        'billing:process-dunning' => [
            'command' => 'billing:process-dunning',
            'description' => 'automations.tasks.processDunning',
            'category' => 'billing',
            'frequency' => 'daily',
            'cron' => '0 0 * * *',
            'expected_interval_minutes' => 1440,
            'sensitive' => true,
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
            'sensitive' => true,
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
        'alerts:escalate' => [
            'command' => 'alerts:escalate',
            'description' => 'automations.tasks.alertsEscalate',
            'category' => 'system',
            'frequency' => 'every15min',
            'cron' => '*/15 * * * *',
            'expected_interval_minutes' => 15,
        ],

        // ── Registration (abandon onboarding) ─────────────────
        'registration:detect-abandoned' => [
            'command' => 'registration:detect-abandoned',
            'description' => 'automations.tasks.registrationDetectAbandoned',
            'category' => 'system',
            'frequency' => 'daily',
            'cron' => '0 8 * * *',
            'expected_interval_minutes' => 1440,
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

    /**
     * Sensitive tasks perform mass financial mutations (renewals, dunning, trial
     * expiry, scheduled collection). Manual execution from the cockpit must be gated
     * by an explicit confirmation + a dedicated permission — never freely triggerable.
     */
    public static function isSensitive(string $task): bool
    {
        return (bool) (self::TASKS[$task]['sensitive'] ?? false);
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
     * Scheduler liveness, driven by the per-minute heartbeat (scheduler:heartbeat) —
     * NOT by business-task history (which only runs every few minutes and is wiped on
     * a staging reset). Distinguishes:
     *   - 'unknown' : no heartbeat recorded yet (fresh boot / right after a reset).
     *                 Never promoted to 'ok' — absence of a signal is not health.
     *   - 'ok'      : heartbeat within the healthy window → cron is firing.
     *   - 'silent'  : heartbeat late (a few minutes) → possibly a transient delay.
     *   - 'dead'    : heartbeat stale beyond the dead threshold → cron stopped.
     *
     * `last_activity_at` keeps reporting the last business run so the cockpit can tell
     * "scheduler alive but no business task has run yet" apart from "scheduler alive
     * and working".
     *
     * @return array{status: 'ok'|'silent'|'dead'|'unknown', last_heartbeat_at: ?string, heartbeat_age_seconds: ?int, last_activity_at: ?string, business_runs_recorded: bool}
     */
    public static function globalHealth(?int $okThresholdSeconds = null, ?int $deadThresholdSeconds = null): array
    {
        $okThresholdSeconds ??= (int) config('automations.heartbeat_ok_seconds', 180);
        $deadThresholdSeconds ??= (int) config('automations.heartbeat_dead_seconds', 300);

        $lastBeat = SchedulerHeartbeat::lastBeatAt();
        $lastRun = ScheduledTaskRun::query()->latest('started_at')->first();
        $lastActivityAt = $lastRun?->started_at?->toIso8601String();

        if ($lastBeat === null) {
            // No liveness signal at all → unknown (NOT dead, NOT ok).
            return [
                'status' => 'unknown',
                'last_heartbeat_at' => null,
                'heartbeat_age_seconds' => null,
                'last_activity_at' => $lastActivityAt,
                'business_runs_recorded' => $lastRun !== null,
            ];
        }

        $ageSeconds = (int) abs($lastBeat->diffInSeconds(now()));

        $status = 'ok';
        if ($ageSeconds > $deadThresholdSeconds) {
            $status = 'dead';
        } elseif ($ageSeconds > $okThresholdSeconds) {
            $status = 'silent';
        }

        return [
            'status' => $status,
            'last_heartbeat_at' => $lastBeat->toIso8601String(),
            'heartbeat_age_seconds' => $ageSeconds,
            'last_activity_at' => $lastActivityAt,
            'business_runs_recorded' => $lastRun !== null,
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
