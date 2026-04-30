<?php

namespace App\Modules\Platform\Dashboard;

use App\Core\Ai\ReadModels\AiHealthReadService;
use App\Core\Alerts\PlatformAlert;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Email\EmailLog;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated system health — extracted from SystemHealthController
 * to keep the controller under 250 lines and isolate DB:: usage.
 */
class SystemHealthService
{
    public function check(): array
    {
        $sections = [
            $this->emailSection(),
            $this->aiSection(),
            $this->queueSection(),
            $this->schedulerSection(),
            $this->databaseSection(),
            $this->diskSection(),
            $this->alertsSection(),
        ];

        $hasCritical = collect($sections)->contains('status', 'critical');
        $hasWarning = collect($sections)->contains('status', 'warning');

        return [
            'status' => $hasCritical ? 'critical' : ($hasWarning ? 'warning' : 'healthy'),
            'checked_at' => now()->toIso8601String(),
            'sections' => $sections,
        ];
    }

    private function emailSection(): array
    {
        try {
            $sent24h = EmailLog::sent()->where('created_at', '>=', now()->subDay())->count();
            $failed24h = EmailLog::failed()->where('created_at', '>=', now()->subDay())->count();
            $failRate = ($sent24h + $failed24h) > 0 ? round($failed24h / ($sent24h + $failed24h) * 100, 1) : 0;
            $status = ($failed24h > 10 || $failRate > 20) ? 'critical' : (($failed24h > 0 || $failRate > 5) ? 'warning' : 'healthy');

            return ['key' => 'email', 'label' => 'Email', 'status' => $status, 'details' => ['sent_24h' => $sent24h, 'failed_24h' => $failed24h, 'fail_rate' => $failRate]];
        } catch (\Throwable) {
            return ['key' => 'email', 'label' => 'Email', 'status' => 'critical', 'details' => ['error' => 'Unable to check']];
        }
    }

    private function aiSection(): array
    {
        try {
            $health = AiHealthReadService::check();
            $status = $health['healthy'] ? 'healthy' : (! $health['provider']['healthy'] ? 'critical' : 'warning');

            return ['key' => 'ai', 'label' => 'AI', 'status' => $status, 'details' => [
                'provider' => $health['provider']['key'] ?? 'unknown',
                'provider_status' => $health['provider']['status'] ?? 'unknown',
                'queue_pending' => $health['queue']['pending_jobs'] ?? 0,
                'queue_failed_24h' => $health['queue']['failed_jobs_24h'] ?? 0,
            ]];
        } catch (\Throwable) {
            return ['key' => 'ai', 'label' => 'AI', 'status' => 'warning', 'details' => ['error' => 'Unable to check']];
        }
    }

    private function queueSection(): array
    {
        try {
            $stats = ScheduledTaskRegistry::queueStats();
            $pending = ($stats['queue_default_pending'] ?? 0) + ($stats['queue_ai_pending'] ?? 0);
            $failed = ($stats['queue_default_failed_24h'] ?? 0) + ($stats['queue_ai_failed_24h'] ?? 0);
            $status = ($failed > 5 || $pending > 500) ? 'critical' : (($failed > 0 || $pending > 100) ? 'warning' : 'healthy');

            return ['key' => 'queues', 'label' => 'Queues', 'status' => $status, 'details' => [
                'default_pending' => $stats['queue_default_pending'] ?? 0, 'ai_pending' => $stats['queue_ai_pending'] ?? 0,
                'default_failed_24h' => $stats['queue_default_failed_24h'] ?? 0, 'ai_failed_24h' => $stats['queue_ai_failed_24h'] ?? 0,
            ]];
        } catch (\Throwable) {
            return ['key' => 'queues', 'label' => 'Queues', 'status' => 'critical', 'details' => ['error' => 'Unable to check']];
        }
    }

    private function schedulerSection(): array
    {
        try {
            $recentRuns = ScheduledTaskRun::where('created_at', '>=', now()->subHours(2));
            $failedCount = (clone $recentRuns)->where('status', 'failed')->count();
            $totalCount = (clone $recentRuns)->count();
            $lastRun = ScheduledTaskRun::latest('created_at')->first();
            $status = $totalCount === 0 ? 'critical' : ($failedCount > 2 ? 'critical' : ($failedCount > 0 ? 'warning' : 'healthy'));

            return ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => $status, 'details' => [
                'runs_2h' => $totalCount, 'failed_2h' => $failedCount, 'last_run_at' => $lastRun?->created_at?->toIso8601String(),
            ]];
        } catch (\Throwable) {
            return ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => 'critical', 'details' => ['error' => 'Unable to check']];
        }
    }

    private function databaseSection(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 1);
            $status = $latencyMs > 500 ? 'critical' : ($latencyMs > 100 ? 'warning' : 'healthy');

            return ['key' => 'database', 'label' => 'Database', 'status' => $status, 'details' => ['latency_ms' => $latencyMs, 'connection' => config('database.default')]];
        } catch (\Throwable) {
            return ['key' => 'database', 'label' => 'Database', 'status' => 'critical', 'details' => ['error' => 'Database unreachable']];
        }
    }

    private function diskSection(): array
    {
        try {
            $totalBytes = disk_total_space(storage_path());
            $freeBytes = disk_free_space(storage_path());
            if ($totalBytes === false || $freeBytes === false) {
                return ['key' => 'disk', 'label' => 'Disk', 'status' => 'warning', 'details' => ['error' => 'Unable to read']];
            }
            $usedPercent = round((1 - $freeBytes / $totalBytes) * 100, 1);
            $status = $usedPercent > 95 ? 'critical' : ($usedPercent > 85 ? 'warning' : 'healthy');

            return ['key' => 'disk', 'label' => 'Disk', 'status' => $status, 'details' => ['used_percent' => $usedPercent, 'free_gb' => round($freeBytes / 1073741824, 1)]];
        } catch (\Throwable) {
            return ['key' => 'disk', 'label' => 'Disk', 'status' => 'warning', 'details' => ['error' => 'Unable to check']];
        }
    }

    private function alertsSection(): array
    {
        try {
            $activeCritical = PlatformAlert::active()->critical()->count();
            $activeTotal = PlatformAlert::active()->count();
            $status = $activeCritical > 0 ? 'critical' : ($activeTotal > 5 ? 'warning' : 'healthy');

            return ['key' => 'alerts', 'label' => 'Alerts', 'status' => $status, 'details' => ['active_critical' => $activeCritical, 'active_total' => $activeTotal]];
        } catch (\Throwable) {
            return ['key' => 'alerts', 'label' => 'Alerts', 'status' => 'warning', 'details' => ['error' => 'Unable to check']];
        }
    }
}
