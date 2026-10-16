<?php

namespace Tests\Feature;

use App\Core\Automation\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * External scheduler-liveness endpoint (GET /health/scheduler). Token-gated,
 * disabled when no token is configured, deploy-safe (unknown → healthy).
 */
class SchedulerHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_when_no_token_configured(): void
    {
        config(['automations.monitor_token' => '']);
        $this->getJson('/health/scheduler?token=anything')->assertStatus(404);
    }

    public function test_wrong_token_is_rejected(): void
    {
        config(['automations.monitor_token' => 'the-secret']);
        $this->getJson('/health/scheduler?token=wrong')->assertStatus(404);
    }

    public function test_healthy_when_heartbeat_recent(): void
    {
        config(['automations.monitor_token' => 'the-secret']);
        SchedulerHeartbeat::pulse();

        $this->getJson('/health/scheduler?token=the-secret')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok', 'healthy' => true]);
    }

    public function test_unhealthy_503_when_heartbeat_stale(): void
    {
        config(['automations.monitor_token' => 'the-secret']);
        SchedulerHeartbeat::create(['beat_at' => now()->subMinutes(10), 'environment' => 'testing']);

        $this->getJson('/health/scheduler', ['X-Monitor-Token' => 'the-secret'])
            ->assertStatus(503)
            ->assertJson(['status' => 'dead', 'healthy' => false]);
    }

    public function test_no_heartbeat_within_grace_is_starting_not_healthy(): void
    {
        config(['automations.monitor_token' => 'the-secret', 'automations.heartbeat_grace_seconds' => 600]);
        // Fresh install (installed_at ~ now, no beat yet) → grace → 200 'starting' but NOT healthy.
        $this->getJson('/health/scheduler?token=the-secret')
            ->assertStatus(200)
            ->assertJson(['status' => 'starting', 'healthy' => false]);
    }

    public function test_no_heartbeat_beyond_grace_is_dead_503(): void
    {
        config(['automations.monitor_token' => 'the-secret', 'automations.heartbeat_grace_seconds' => 600]);
        // Installed long ago, scheduler never beat → grace elapsed → unhealthy 503.
        SchedulerHeartbeat::singleton()->update(['installed_at' => now()->subMinutes(30), 'beat_at' => null]);

        $this->getJson('/health/scheduler?token=the-secret')
            ->assertStatus(503)
            ->assertJson(['status' => 'dead', 'healthy' => false]);
    }
}
