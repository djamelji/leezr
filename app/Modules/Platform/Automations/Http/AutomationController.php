<?php

namespace App\Modules\Platform\Automations\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Automation\Jobs\RunScheduledTaskJob;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Automation\ScheduledTaskState;
use App\Modules\Platform\Automations\AutomationCockpitService;
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
    public function index(AutomationCockpitService $cockpit): JsonResponse
    {
        return response()->json($cockpit->overview());
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

        // A disabled task must not run — not from the scheduler (->when filter) nor
        // manually. Re-enable it first.
        if (! ScheduledTaskState::isEnabled($task)) {
            return response()->json([
                'message' => 'This task is disabled. Enable it before running.',
                'code' => 'task_disabled',
            ], 422);
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

    /**
     * POST /platform/automations/toggle
     *
     * Enable or disable a task. Disabling is REAL: the scheduler's ->when() filter
     * skips the command and manual run is refused. Audited.
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task' => 'required|string|max:100',
            'enabled' => 'required|boolean',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);

        $task = $validated['task'];

        if (! ScheduledTaskRegistry::exists($task)) {
            return response()->json(['message' => 'Unknown task.'], 404);
        }

        $enabled = $request->boolean('enabled');
        $actor = $request->user('platform');

        // Re-enabling a sensitive task resumes mass financial execution → requires the
        // dedicated permission. Disabling (a safety brake) stays allowed for any operator.
        if ($enabled && ScheduledTaskRegistry::isSensitive($task)
            && (! $actor || ! $actor->hasPermission('run_sensitive_automations'))) {
            return response()->json([
                'message' => 'Re-enabling a sensitive (mass financial) task requires the run_sensitive_automations permission.',
                'code' => 'sensitive_permission_required',
            ], 403);
        }

        $actorLabel = $actor ? ($actor->email ?? (string) $actor->id) : null;

        ScheduledTaskState::setEnabled($task, $enabled, $actorLabel, $validated['reason'] ?? null);

        app(AuditLogger::class)->logPlatform(
            $enabled ? AuditAction::AUTOMATION_TASK_ENABLED : AuditAction::AUTOMATION_TASK_DISABLED,
            'automation',
            $task,
            [
                'severity' => $enabled ? 'info' : 'warning',
                'metadata' => ['reason' => $validated['reason'] ?? null],
            ],
        );

        return response()->json([
            'task' => $task,
            'enabled' => $enabled,
            'message' => $enabled ? "Task {$task} enabled." : "Task {$task} disabled.",
        ]);
    }
}
