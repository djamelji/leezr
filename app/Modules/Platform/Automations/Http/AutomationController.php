<?php

namespace App\Modules\Platform\Automations\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Automation\Jobs\RunScheduledTaskJob;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
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
                'sensitive' => ScheduledTaskRegistry::isSensitive($name),
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
            'confirm' => 'sometimes|boolean',
        ]);

        $task = $request->input('task');

        if (! ScheduledTaskRegistry::exists($task)) {
            return response()->json(['message' => 'Unknown task.'], 404);
        }

        $sensitive = ScheduledTaskRegistry::isSensitive($task);

        // Sensitive tasks perform mass financial mutations (renewals, dunning, trial
        // expiry, scheduled collection). They must NOT be freely triggerable: require a
        // dedicated permission AND an explicit confirmation flag.
        if ($sensitive) {
            $user = $request->user('platform');

            if (! $user || ! $user->hasPermission('run_sensitive_automations')) {
                return response()->json([
                    'message' => 'This task performs mass financial operations and requires the run_sensitive_automations permission.',
                    'code' => 'sensitive_permission_required',
                ], 403);
            }

            if ($request->boolean('confirm') !== true) {
                return response()->json([
                    'message' => 'This task is sensitive (mass financial). Resend with confirm=true to execute.',
                    'code' => 'confirmation_required',
                    'sensitive' => true,
                ], 422);
            }
        }

        // Double-execution guard: a short atomic lock around dispatch + a check that no
        // non-stale run is already in progress (scheduled OR manual). Scheduled runs use
        // withoutOverlapping on their side; this prevents a manual run from overlapping.
        $lock = Cache::lock("automation:run:{$task}", 15);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'A run for this task is already being dispatched.',
                'code' => 'already_running',
            ], 409);
        }

        try {
            $inProgress = ScheduledTaskRun::where('task', $task)
                ->where('status', 'running')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->exists();

            if ($inProgress) {
                return response()->json([
                    'message' => 'This task is already running.',
                    'code' => 'already_running',
                ], 409);
            }

            $run = ScheduledTaskRun::create([
                'task' => $task,
                'status' => 'running',
                'started_at' => now(),
                'environment' => app()->environment(),
            ]);

            RunScheduledTaskJob::dispatch($task, $run->id);

            // Trace every manual execution (actor, IP, sensitivity) — ADR-130.
            app(AuditLogger::class)->logPlatform(
                AuditAction::AUTOMATION_RUN_MANUAL,
                'automation',
                $task,
                [
                    'severity' => $sensitive ? 'warning' : 'info',
                    'metadata' => ['run_id' => $run->id, 'sensitive' => $sensitive],
                ],
            );

            return response()->json([
                'run_id' => $run->id,
                'status' => 'dispatched',
                'message' => "Task {$task} dispatched for execution.",
            ]);
        } finally {
            $lock->release();
        }
    }
}
