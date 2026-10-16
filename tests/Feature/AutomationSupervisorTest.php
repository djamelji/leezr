<?php

namespace Tests\Feature;

use App\Core\Alerts\PlatformAlert;
use App\Core\Automation\AutomationSupervisor;
use App\Core\Automation\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Scheduler/queue supervision via the Alert Center (ADR-438).
 * Covers requirement #6: scheduler silence, repeated task failures — raised as
 * platform alerts and auto-resolved when the condition clears.
 */
class AutomationSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private function supervise(): void
    {
        (new AutomationSupervisor())->evaluate();
    }

    public function test_scheduler_silence_raises_and_resolves_alert(): void
    {
        // Stale heartbeat → globalHealth = dead → alert raised.
        \App\Core\Automation\SchedulerHeartbeat::create(['beat_at' => now()->subMinutes(15), 'environment' => 'testing']);
        $this->supervise();
        $this->assertDatabaseHas('platform_alerts', [
            'type' => 'scheduler_silent', 'status' => 'active', 'severity' => 'critical',
        ]);

        // Fresh heartbeat → health ok → alert auto-resolved.
        \App\Core\Automation\SchedulerHeartbeat::pulse();
        $this->supervise();

        $alert = PlatformAlert::where('type', 'scheduler_silent')->first();
        $this->assertNotNull($alert);
        $this->assertSame('resolved', $alert->status);
    }

    public function test_repeated_task_failures_raise_and_resolve_alert(): void
    {
        // Keep the scheduler "alive" so only the task-failing rule is under test.
        ScheduledTaskRun::create(['task' => 'alerts:evaluate', 'status' => 'success', 'started_at' => now(), 'environment' => 'testing']);

        foreach (range(1, 3) as $i) {
            ScheduledTaskRun::create([
                'task' => 'email:fetch-inbox', 'status' => 'failed',
                'started_at' => now()->subMinutes(30 - $i), 'finished_at' => now(), 'environment' => 'testing',
            ]);
        }

        $this->supervise();
        $this->assertDatabaseHas('platform_alerts', [
            'type' => 'automation_task_failing', 'status' => 'active',
        ]);

        // A later successful run clears it.
        ScheduledTaskRun::create([
            'task' => 'email:fetch-inbox', 'status' => 'success',
            'started_at' => now(), 'finished_at' => now(), 'environment' => 'testing',
        ]);
        $this->supervise();

        $alert = PlatformAlert::where('type', 'automation_task_failing')->first();
        $this->assertSame('resolved', $alert->status);
    }

    public function test_alerts_evaluate_command_invokes_supervision(): void
    {
        // Stale heartbeat → dead → the scheduled evaluator must produce the scheduler alert.
        \App\Core\Automation\SchedulerHeartbeat::create(['beat_at' => now()->subMinutes(15), 'environment' => 'testing']);
        Artisan::call('alerts:evaluate');
        $this->assertDatabaseHas('platform_alerts', ['type' => 'scheduler_silent', 'status' => 'active']);
    }
}
