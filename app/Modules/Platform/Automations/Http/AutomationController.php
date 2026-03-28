<?php

namespace App\Modules\Platform\Automations\Http;

use App\Core\Automation\Jobs\RunScheduledTaskJob;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class AutomationController extends Controller
{
    /**
     * GET /platform/automations
     *
     * Returns summary KPIs, queue stats, global health,
     * and per-task details with health badges.
     */
    public function index(): JsonResponse
    {
        // ── Auto-fix stale "running" records (> 10 min) ──
        ScheduledTaskRun::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Marked as failed: running for more than 10 minutes (stale)',
            ]);

        // ── 24h aggregate stats per task ─────────────────
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

        // ── Latest run per task ──────────────────────────
        $latestRunIds = ScheduledTaskRun::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('task')
            ->pluck('id');

        $latestRuns = ScheduledTaskRun::whereIn('id', $latestRunIds)
            ->get()
            ->keyBy('task');

        // ── Queue stats (via Registry — no DB:: in controllers) ──
        $queueStats = ScheduledTaskRegistry::queueStats();

        // ── Summary KPIs ─────────────────────────────────
        $totalSuccess = $taskStats->sum('success_count');
        $totalFailed = $taskStats->sum('failed_count');
        $avgDuration = $taskStats->avg('avg_duration_ms');

        // ── Build per-task response ──────────────────────
        $tasks = [];
        foreach (ScheduledTaskRegistry::all() as $name => $meta) {
            $stats = $taskStats->get($name);
            $lastRun = $latestRuns->get($name);

            $tasks[] = [
                'name' => $name,
                'description' => $meta['description'],
                'category' => $meta['category'],
                'frequency' => $meta['frequency'],
                'cron' => $meta['cron'],
                'health' => ScheduledTaskRegistry::computeHealth($name, $lastRun),
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

        return response()->json([
            'summary' => array_merge([
                'success_24h' => $totalSuccess,
                'failed_24h' => $totalFailed,
                'avg_duration_ms' => $avgDuration ? (int) round($avgDuration) : 0,
            ], $queueStats),
            'scheduler_health' => ScheduledTaskRegistry::globalHealth(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * GET /platform/automations/runs?task=...&page=1
     *
     * Paginated run history for a specific task.
     */
    public function runs(Request $request): JsonResponse
    {
        $request->validate([
            'task' => 'required|string|max:100',
        ]);

        $task = $request->input('task');

        if (! ScheduledTaskRegistry::exists($task)) {
            return response()->json(['message' => 'Unknown task.'], 404);
        }

        $runs = ScheduledTaskRun::query()
            ->task($task)
            ->latest('id')
            ->paginate(20);

        return response()->json($runs);
    }

    /**
     * POST /platform/automations/run
     *
     * Dispatch a task for immediate async execution.
     * Returns immediately with run_id — cockpit gets update via realtime.
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'task' => 'required|string|max:100',
        ]);

        $task = $request->input('task');

        if (! ScheduledTaskRegistry::exists($task)) {
            return response()->json(['message' => 'Unknown task.'], 404);
        }

        // Create the run record
        $run = ScheduledTaskRun::create([
            'task' => $task,
            'status' => 'running',
            'started_at' => now(),
            'environment' => app()->environment(),
        ]);

        // Dispatch async job
        RunScheduledTaskJob::dispatch($task, $run->id);

        return response()->json([
            'run_id' => $run->id,
            'status' => 'dispatched',
            'message' => "Task {$task} dispatched for execution.",
        ]);
    }
}
