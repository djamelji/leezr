<?php

namespace Tests\Feature;

use App\Core\Security\AlertTypeRegistry;
use App\Core\Security\SecurityAlert;
use App\Core\Security\SecurityDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function redisAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cleanRedisKeys(): void
    {
        $redis = \Illuminate\Support\Facades\Redis::connection();

        foreach ($redis->keys('leezr:security:*') ?: [] as $key) {
            $redis->del($key);
        }
    }

    // ─── Threshold detection (requires Redis) ───────────

    public function test_no_alert_below_threshold(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        $definition = AlertTypeRegistry::get('suspicious.login_attempts');

        for ($i = 0; $i < $definition['threshold']; $i++) {
            SecurityDetector::check('suspicious.login_attempts', '192.168.1.1');
        }

        $this->assertDatabaseCount('security_alerts', 0);
    }

    public function test_alert_raised_when_threshold_exceeded(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        $definition = AlertTypeRegistry::get('suspicious.login_attempts');

        for ($i = 0; $i <= $definition['threshold']; $i++) {
            SecurityDetector::check('suspicious.login_attempts', '192.168.1.1');
        }

        $this->assertDatabaseCount('security_alerts', 1);

        $alert = SecurityAlert::first();

        $this->assertEquals('suspicious.login_attempts', $alert->alert_type);
        $this->assertEquals($definition['severity'], $alert->severity);
        $this->assertEquals('open', $alert->status);
        $this->assertNotNull($alert->evidence);
    }

    public function test_only_one_alert_per_window(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        $definition = AlertTypeRegistry::get('suspicious.login_attempts');

        for ($i = 0; $i < $definition['threshold'] + 5; $i++) {
            SecurityDetector::check('suspicious.login_attempts', '192.168.1.1');
        }

        $this->assertDatabaseCount('security_alerts', 1);
    }

    public function test_different_actor_keys_track_independently(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        $definition = AlertTypeRegistry::get('suspicious.login_attempts');

        // IP #1: exceed threshold
        for ($i = 0; $i <= $definition['threshold']; $i++) {
            SecurityDetector::check('suspicious.login_attempts', '192.168.1.1');
        }

        // IP #2: below threshold
        for ($i = 0; $i < $definition['threshold']; $i++) {
            SecurityDetector::check('suspicious.login_attempts', '10.0.0.1');
        }

        $this->assertDatabaseCount('security_alerts', 1);

        $alert = SecurityAlert::first();

        $this->assertStringContainsString('192.168.1.1', json_encode($alert->evidence));
    }

    public function test_alert_includes_company_and_actor(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        $definition = AlertTypeRegistry::get('mass.role_changes');

        for ($i = 0; $i <= $definition['threshold']; $i++) {
            SecurityDetector::check('mass.role_changes', 'user:42', 7, 42);
        }

        $this->assertDatabaseCount('security_alerts', 1);

        $alert = SecurityAlert::first();

        $this->assertEquals(7, $alert->company_id);
        $this->assertEquals(42, $alert->actor_id);
    }

    // ─── Alert lifecycle (no Redis needed) ──────────────

    public function test_alert_lifecycle_open_to_acknowledged(): void
    {
        $alert = SecurityAlert::create([
            'alert_type' => 'suspicious.login_attempts',
            'severity' => 'high',
            'evidence' => ['test' => true],
            'status' => 'open',
            'created_at' => now(),
        ]);

        $this->assertEquals('open', $alert->status);

        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => 1,
            'acknowledged_at' => now(),
        ]);

        $this->assertEquals('acknowledged', $alert->fresh()->status);
    }

    public function test_alert_lifecycle_acknowledged_to_resolved(): void
    {
        $alert = SecurityAlert::create([
            'alert_type' => 'mass.role_changes',
            'severity' => 'medium',
            'evidence' => ['test' => true],
            'status' => 'acknowledged',
            'acknowledged_by' => 1,
            'acknowledged_at' => now(),
            'created_at' => now(),
        ]);

        $alert->update([
            'status' => 'resolved',
            'resolved_by' => 1,
            'resolved_at' => now(),
        ]);

        $this->assertEquals('resolved', $alert->fresh()->status);
    }

    public function test_unknown_alert_type_is_silently_ignored(): void
    {
        // The detector's try/catch + null-check should prevent any issue.
        // Even if Redis is unavailable, the null-check on AlertTypeRegistry returns early.
        SecurityDetector::check('nonexistent.type', 'key');

        $this->assertDatabaseCount('security_alerts', 0);
    }

    // ─── Registry validation (no Redis needed) ──────────

    public function test_alert_type_registry_has_all_types(): void
    {
        $keys = AlertTypeRegistry::keys();

        $this->assertContains('suspicious.login_attempts', $keys);
        $this->assertContains('mass.role_changes', $keys);
        $this->assertContains('abnormal.module_toggling', $keys);
        $this->assertContains('excessive.stream_connections', $keys);
        $this->assertContains('bulk.member_removal', $keys);
        $this->assertContains('unauthorized.access_pattern', $keys);
        $this->assertContains('realtime.event_flood', $keys);
    }

    public function test_each_alert_type_has_required_fields(): void
    {
        foreach (AlertTypeRegistry::all() as $key => $definition) {
            $this->assertArrayHasKey('threshold', $definition, "Alert type '{$key}' missing threshold");
            $this->assertArrayHasKey('window', $definition, "Alert type '{$key}' missing window");
            $this->assertArrayHasKey('severity', $definition, "Alert type '{$key}' missing severity");
            $this->assertArrayHasKey('description', $definition, "Alert type '{$key}' missing description");
            $this->assertContains($definition['severity'], ['low', 'medium', 'high', 'critical'], "Alert type '{$key}' has invalid severity");
        }
    }
}
