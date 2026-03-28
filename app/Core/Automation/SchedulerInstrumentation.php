<?php

namespace App\Core\Automation;

use App\Core\Notifications\NotificationDispatcher;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Platform\Models\PlatformUser;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SchedulerInstrumentation
{
    /** @var array<string, int> Track current run IDs by task name */
    private static array $currentRuns = [];

    /**
     * Hook: called BEFORE the scheduled command runs.
     * Creates a ScheduledTaskRun record with status=running.
     */
    public static function before(string $task): Closure
    {
        return function () use ($task) {
            try {
                $run = ScheduledTaskRun::create([
                    'task' => $task,
                    'status' => 'running',
                    'started_at' => now(),
                    'environment' => app()->environment(),
                ]);

                static::$currentRuns[$task] = $run->id;
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] Failed to instrument before() for {$task}: {$e->getMessage()}");
            }
        };
    }

    /**
     * Hook: called when the scheduled command succeeds.
     * Updates the run record with status=success, captures output, publishes realtime.
     */
    public static function onSuccess(string $task): Closure
    {
        return function () use ($task) {
            try {
                $runId = static::$currentRuns[$task] ?? null;
                if (! $runId) {
                    return;
                }

                $run = ScheduledTaskRun::find($runId);
                if (! $run) {
                    return;
                }

                $output = static::captureOutput($task);
                $durationMs = $run->started_at
                    ? (int) round($run->started_at->diffInMilliseconds(now()))
                    : null;

                $run->update([
                    'status' => 'success',
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'output' => $output ? Str::limit($output, 10000) : null,
                ]);

                static::publishRealtimeEvent($task, 'success', $durationMs);

                unset(static::$currentRuns[$task]);
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] Failed to instrument onSuccess() for {$task}: {$e->getMessage()}");
            }
        };
    }

    /**
     * Hook: called when the scheduled command fails.
     * Updates the run record with status=failed, publishes realtime, dispatches alert.
     */
    public static function onFailure(string $task): Closure
    {
        return function () use ($task) {
            try {
                $runId = static::$currentRuns[$task] ?? null;
                if (! $runId) {
                    return;
                }

                $run = ScheduledTaskRun::find($runId);
                if (! $run) {
                    return;
                }

                $output = static::captureOutput($task);
                $durationMs = $run->started_at
                    ? (int) round($run->started_at->diffInMilliseconds(now()))
                    : null;

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'output' => $output ? Str::limit($output, 10000) : null,
                    'error' => $output ? Str::limit($output, 5000) : 'Task failed (no output captured)',
                ]);

                static::publishRealtimeEvent($task, 'failed', $durationMs);
                static::dispatchFailureAlert($task, $run);

                unset(static::$currentRuns[$task]);
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] Failed to instrument onFailure() for {$task}: {$e->getMessage()}");
            }
        };
    }

    // ── Private helpers ───────────────────────────────────

    /**
     * Read captured output from the scheduler log file.
     */
    private static function captureOutput(string $task): ?string
    {
        $logFile = storage_path('logs/scheduler/'.Str::slug($task, '-').'.log');

        if (! file_exists($logFile)) {
            return null;
        }

        $content = file_get_contents($logFile);

        // Truncate the log file after reading (keep it fresh for next run)
        file_put_contents($logFile, '');

        return $content ?: null;
    }

    /**
     * Publish realtime event for cockpit live updates.
     */
    private static function publishRealtimeEvent(string $task, string $status, ?int $durationMs): void
    {
        try {
            $envelope = EventEnvelope::domain('automation.run.completed', null, [
                'task' => $task,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);

            app(RealtimePublisher::class)->publish($envelope);
        } catch (\Throwable $e) {
            Log::debug("[Scheduler] Realtime publish failed for {$task}: {$e->getMessage()}");
        }
    }

    /**
     * Dispatch a notification alert to platform admins on task failure.
     */
    private static function dispatchFailureAlert(string $task, ScheduledTaskRun $run): void
    {
        try {
            $recipients = PlatformUser::all();
            if ($recipients->isEmpty()) {
                return;
            }

            NotificationDispatcher::send(
                topicKey: 'platform.scheduler_task_failed',
                recipients: $recipients,
                payload: [
                    'task' => $task,
                    'error' => $run->error,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'environment' => $run->environment,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning("[Scheduler] Failure alert dispatch failed for {$task}: {$e->getMessage()}");
        }
    }
}
