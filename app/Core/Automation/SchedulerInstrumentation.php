<?php

namespace App\Core\Automation;

use App\Core\Notifications\NotificationDispatcher;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Platform\Models\PlatformUser;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SchedulerInstrumentation
{
    /**
     * Hook: called BEFORE the scheduled command runs.
     * Creates a ScheduledTaskRun record with status=running.
     * Also ensures the scheduler log directory exists.
     */
    public static function before(string $task): Closure
    {
        return function () use ($task) {
            try {
                // Ensure log directory exists for appendOutputTo
                $logDir = storage_path('logs/scheduler');
                if (! File::isDirectory($logDir)) {
                    File::makeDirectory($logDir, 0755, true);
                }

                ScheduledTaskRun::create([
                    'task' => $task,
                    'status' => 'running',
                    'started_at' => now(),
                    'environment' => app()->environment(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] before({$task}): {$e->getMessage()}");
            }
        };
    }

    /**
     * Hook: called when the scheduled command succeeds.
     * Finds the latest "running" record for this task and updates it.
     * No static state dependency — DB lookup is the source of truth.
     */
    public static function onSuccess(string $task): Closure
    {
        return function () use ($task) {
            try {
                $run = static::findRunningRecord($task);
                if (! $run) {
                    return;
                }

                $output = static::captureOutput($task);
                $durationMs = static::computeDuration($run);

                $run->update([
                    'status' => 'success',
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'output' => $output ? Str::limit($output, 10000) : null,
                ]);

                static::publishRealtimeEvent($task, 'success', $durationMs);
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] onSuccess({$task}): {$e->getMessage()}");
            }
        };
    }

    /**
     * Hook: called when the scheduled command fails.
     * Finds the latest "running" record for this task and updates it.
     */
    public static function onFailure(string $task): Closure
    {
        return function () use ($task) {
            try {
                $run = static::findRunningRecord($task);
                if (! $run) {
                    return;
                }

                $output = static::captureOutput($task);
                $durationMs = static::computeDuration($run);

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'output' => $output ? Str::limit($output, 10000) : null,
                    'error' => $output ? Str::limit($output, 5000) : 'Task failed (no output captured)',
                ]);

                static::publishRealtimeEvent($task, 'failed', $durationMs);
                static::dispatchFailureAlert($task, $run);
            } catch (\Throwable $e) {
                Log::warning("[Scheduler] onFailure({$task}): {$e->getMessage()}");
            }
        };
    }

    // ── Private helpers ───────────────────────────────────

    /**
     * Find the latest "running" record for a task.
     * This is the source of truth — no static state needed.
     */
    private static function findRunningRecord(string $task): ?ScheduledTaskRun
    {
        return ScheduledTaskRun::where('task', $task)
            ->where('status', 'running')
            ->latest('id')
            ->first();
    }

    /**
     * Compute duration in ms from started_at to now.
     */
    private static function computeDuration(ScheduledTaskRun $run): ?int
    {
        if (! $run->started_at) {
            return null;
        }

        return (int) abs($run->started_at->diffInMilliseconds(now()));
    }

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
