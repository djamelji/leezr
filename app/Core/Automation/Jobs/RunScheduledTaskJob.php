<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;   // 5 min max
    public int $tries = 1;       // no automatic retry

    public function __construct(
        public readonly string $taskName,
        public readonly int $runId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $run = ScheduledTaskRun::find($this->runId);
        if (! $run) {
            Log::warning("[RunScheduledTaskJob] Run #{$this->runId} not found for task {$this->taskName}");

            return;
        }

        $meta = ScheduledTaskRegistry::get($this->taskName);
        $command = $meta['command'] ?? null;
        if (! $command) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => "Unknown command for task: {$this->taskName}",
            ]);

            return;
        }

        $start = microtime(true);

        try {
            $exitCode = Artisan::call($command);
            $output = Artisan::output();
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $run->update([
                'status' => $exitCode === 0 ? 'success' : 'failed',
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'output' => Str::limit($output, 10000),
                'error' => $exitCode !== 0 ? "Exit code: {$exitCode}\n".Str::limit($output, 5000) : null,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'error' => Str::limit($e->getMessage(), 5000),
            ]);
        }

        // Publish realtime event for cockpit live update
        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain('automation.run.completed', null, [
                    'task' => $this->taskName,
                    'status' => $run->fresh()->status,
                    'duration_ms' => $durationMs ?? null,
                ])
            );
        } catch (\Throwable $e) {
            Log::debug("[RunScheduledTaskJob] Realtime publish failed: {$e->getMessage()}");
        }
    }
}
