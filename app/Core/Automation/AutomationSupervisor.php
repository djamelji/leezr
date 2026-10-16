<?php

namespace App\Core\Automation;

use App\Core\Alerts\PlatformAlert;

/**
 * Supervision of the scheduler + queues, surfaced through the existing Alert Center
 * (ADR-438) — no parallel monitoring infrastructure. Called from alerts:evaluate.
 *
 * Rules (fingerprint-deduplicated, auto-resolving):
 *   - scheduler_silent        : no scheduler activity for too long (globalHealth=dead)
 *   - queue_backlog           : pending jobs above threshold (workers down / stuck)
 *   - automation_task_failing : a task whose last run failed and keeps failing in 24h
 *
 * Caveat (documented): a FULLY dead scheduler cannot self-report here because this
 * evaluator is itself a scheduled task. True liveness requires an external uptime
 * monitor hitting a health endpoint; this covers partial stalls + late recovery.
 */
class AutomationSupervisor
{
    private int $created = 0;
    private int $resolved = 0;

    public function evaluate(): array
    {
        $this->evaluateSchedulerSilent();
        $this->evaluateQueueBacklog();
        $this->evaluateTaskFailing();

        return ['created' => $this->created, 'resolved' => $this->resolved];
    }

    private function evaluateSchedulerSilent(): void
    {
        $health = ScheduledTaskRegistry::globalHealth();
        $fingerprint = PlatformAlert::fingerprint('infra', 'scheduler_silent', 'scheduler', 1);

        if ($health['status'] === 'dead') {
            PlatformAlert::updateOrCreate(['fingerprint' => $fingerprint], [
                'source' => 'infra',
                'type' => 'scheduler_silent',
                'severity' => 'critical',
                'status' => 'active',
                'company_id' => null,
                'title' => 'Scheduler appears stopped',
                'description' => 'No scheduler heartbeat for '.(int) round(($health['heartbeat_age_seconds'] ?? 0) / 60)
                    .' minutes. Check the cron (schedule:run) on the server.',
                'metadata' => $health,
                'target_type' => 'scheduler',
                'target_id' => 1,
            ]);
            $this->created++;
        } else {
            $this->resolveByFingerprint($fingerprint);
        }
    }

    private function evaluateQueueBacklog(): void
    {
        $threshold = (int) config('automations.queue_backlog_threshold', 200);
        $stats = ScheduledTaskRegistry::queueStats();
        $pending = (int) ($stats['queue_default_pending'] ?? 0) + (int) ($stats['queue_ai_pending'] ?? 0);
        $fingerprint = PlatformAlert::fingerprint('infra', 'queue_backlog', 'queue', 1);

        if ($pending > $threshold) {
            PlatformAlert::updateOrCreate(['fingerprint' => $fingerprint], [
                'source' => 'infra',
                'type' => 'queue_backlog',
                'severity' => 'warning',
                'status' => 'active',
                'company_id' => null,
                'title' => "Queue backlog: {$pending} pending jobs",
                'description' => "{$pending} jobs are pending (threshold {$threshold}). A worker may be down or stuck.",
                'metadata' => $stats,
                'target_type' => 'queue',
                'target_id' => 1,
            ]);
            $this->created++;
        } else {
            $this->resolveByFingerprint($fingerprint);
        }
    }

    private function evaluateTaskFailing(): void
    {
        $threshold = (int) config('automations.task_failure_threshold', 3);
        $since = now()->subDay();

        $failCounts = ScheduledTaskRun::query()
            ->where('created_at', '>=', $since)
            ->where('status', 'failed')
            ->selectRaw('task, COUNT(*) as c')
            ->groupBy('task')
            ->pluck('c', 'task');

        $latestIds = ScheduledTaskRun::query()->selectRaw('MAX(id) as id')->groupBy('task')->pluck('id');
        $latest = ScheduledTaskRun::whereIn('id', $latestIds)->get()->keyBy('task');

        foreach (ScheduledTaskRegistry::names() as $task) {
            $fingerprint = PlatformAlert::fingerprint('infra', 'automation_task_failing', 'task', crc32($task));
            $lastFailed = ($latest->get($task)?->status) === 'failed';
            $count = (int) ($failCounts[$task] ?? 0);

            if ($lastFailed && $count >= $threshold) {
                PlatformAlert::updateOrCreate(['fingerprint' => $fingerprint], [
                    'source' => 'infra',
                    'type' => 'automation_task_failing',
                    'severity' => 'critical',
                    'status' => 'active',
                    'company_id' => null,
                    'title' => "Automation '{$task}' is failing",
                    'description' => "Task '{$task}' failed {$count} times in 24h and its last run failed.",
                    'metadata' => ['task' => $task, 'failed_24h' => $count],
                    'target_type' => 'task',
                    'target_id' => crc32($task),
                ]);
                $this->created++;
            } else {
                $this->resolveByFingerprint($fingerprint);
            }
        }
    }

    private function resolveByFingerprint(string $fingerprint): void
    {
        $alert = PlatformAlert::active()->where('fingerprint', $fingerprint)->first();

        if ($alert) {
            $alert->resolve();
            $this->resolved++;
        }
    }
}
