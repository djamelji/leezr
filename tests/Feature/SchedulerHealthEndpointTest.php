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

    public function test_unknown_is_deploy_safe_healthy(): void
    {
        config(['automations.monitor_token' => 'the-secret']);
        // No heartbeat yet (fresh / right after a reset) → must NOT false-alert.
        $this->getJson('/health/scheduler?token=the-secret')
            ->assertStatus(200)
            ->assertJson(['status' => 'unknown', 'healthy' => true]);
    }
}
