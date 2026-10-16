<?php

namespace Tests\Feature;

use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Automation\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Scheduler health is driven by the per-minute heartbeat, independent of business
 * task history. Critically, "no signal yet" must read as UNKNOWN — never ok.
 */
class SchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_heartbeat_is_unknown_not_dead_not_ok(): void
    {
        // Fresh / post-reset: no heartbeat and no runs.
        $h = ScheduledTaskRegistry::globalHealth();

        $this->assertSame('unknown', $h['status'], 'Absence of a heartbeat must be unknown, not ok and not dead.');
        $this->assertNull($h['last_heartbeat_at']);
        $this->assertFalse($h['business_runs_recorded']);
    }

    public function test_unknown_even_when_business_runs_exist_but_no_heartbeat(): void
    {
        // A wiped heartbeat but leftover business rows must still not read as ok.
        ScheduledTaskRun::create(['task' => 'alerts:evaluate', 'status' => 'success', 'started_at' => now(), 'environment' => 'testing']);

        $h = ScheduledTaskRegistry::globalHealth();
        $this->assertSame('unknown', $h['status']);
        $this->assertTrue($h['business_runs_recorded']);
    }

    public function test_recent_heartbeat_is_ok(): void
    {
        SchedulerHeartbeat::pulse();

        $h = ScheduledTaskRegistry::globalHealth();
        $this->assertSame('ok', $h['status']);
        $this->assertNotNull($h['last_heartbeat_at']);
        $this->assertLessThanOrEqual(5, $h['heartbeat_age_seconds']);
    }

    public function test_stale_heartbeat_is_dead(): void
    {
        SchedulerHeartbeat::create(['beat_at' => now()->subMinutes(10), 'environment' => 'testing']);

        $this->assertSame('dead', ScheduledTaskRegistry::globalHealth()['status']);
    }

    public function test_slightly_late_heartbeat_is_silent(): void
    {
        SchedulerHeartbeat::create(['beat_at' => now()->subMinutes(4), 'environment' => 'testing']);

        $this->assertSame('silent', ScheduledTaskRegistry::globalHealth()['status']);
    }

    public function test_heartbeat_command_makes_health_ok(): void
    {
        $this->assertSame('unknown', ScheduledTaskRegistry::globalHealth()['status']);

        Artisan::call('scheduler:heartbeat');

        $this->assertSame('ok', ScheduledTaskRegistry::globalHealth()['status']);
        $this->assertNotNull(SchedulerHeartbeat::lastBeatAt());
    }

    public function test_heartbeat_command_is_idempotent_single_row(): void
    {
        Artisan::call('scheduler:heartbeat');
        Artisan::call('scheduler:heartbeat');

        $this->assertSame(1, SchedulerHeartbeat::query()->count(), 'Heartbeat must stay a single row.');
    }
}
