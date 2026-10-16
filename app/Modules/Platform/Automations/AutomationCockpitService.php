<?php

namespace App\Modules\Platform\Automations;

use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Automation\ScheduledTaskState;

/**
 * Builds the Automations cockpit overview: summary KPIs, queue stats, global
 * scheduler health, and per-task details (health, enable/disable, sensitivity,
 * last run, 24h aggregates). Kept out of the controller for size/testability.
 */
class AutomationCockpitService
{
    public function overview(): array
    {
        $this->failStaleRunningRecords();

        $since = now()->subDay();

        $taskStats = ScheduledTaskRun::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('task')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('AVG(duration_ms) as avg_duration_ms')
            ->groupBy('task')
            ->get()
            ->keyBy('task');

        $latestRunIds = ScheduledTaskRun::query()->selectRaw('MAX(id) as id')->groupBy('task')->pluck('id');
        $latestRuns = ScheduledTaskRun::whereIn('id', $latestRunIds)->get()->keyBy('task');

        $queueStats = ScheduledTaskRegistry::queueStats();
        $states = ScheduledTaskState::map();

        $tasks = [];
        foreach (ScheduledTaskRegistry::all() as $name => $meta) {
            $stats = $taskStats->get($name);
            $lastRun = $latestRuns->get($name);
            $enabled = $states[$name] ?? true;

            $tasks[] = [
                'name' => $name,
                'description' => $meta['description'],
                'category' => $meta['category'],
                'frequency' => $meta['frequency'],
                'cron' => $meta['cron'],
                'sensitive' => ScheduledTaskRegistry::isSensitive($name),
                'enabled' => $enabled,
                // 'disabled' is a distinct cockpit state from health (which reflects execution).
                'health' => $enabled ? ScheduledTaskRegistry::computeHealth($name, $lastRun) : 'disabled',
                'next_run_at' => ScheduledTaskRegistry::nextRunAt($name)?->toIso8601String(),
                'last_run_at' => $lastRun?->started_at?->toIso8601String(),
                'last_status' => $lastRun?->status,
                'last_duration_ms' => $lastRun?->duration_ms,
                'last_output' => $lastRun?->output,
                'last_error' => $lastRun?->error,
                'runs_count_24h' => (int) ($stats?->total_count ?? 0),
                'success_count_24h' => (int) ($stats?->success_count ?? 0),
                'failed_count_24h' => (int) ($stats?->failed_count ?? 0),
                'avg_duration_24h' => $stats ? (int) round($stats->avg_duration_ms) : null,
            ];
        }

        return [
            'summary' => array_merge([
                'success_24h' => $taskStats->sum('success_count'),
                'failed_24h' => $taskStats->sum('failed_count'),
                'avg_duration_ms' => ($avg = $taskStats->avg('avg_duration_ms')) ? (int) round($avg) : 0,
            ], $queueStats),
            'scheduler_health' => ScheduledTaskRegistry::globalHealth(),
            'tasks' => $tasks,
        ];
    }

    /**
     * Auto-fail runs stuck in "running" for more than 10 minutes so a crashed run
     * never pins a task's health as permanently "running".
     */
    private function failStaleRunningRecords(): void
    {
        ScheduledTaskRun::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Marked as failed: running for more than 10 minutes (stale)',
            ]);
    }
}
