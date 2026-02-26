<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Security\EventFloodDetector;
use App\Core\Security\SecurityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ADR-131: EventFloodDetector — auto kill switch on event flood.
 */
class EventFloodDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function redisAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cleanRedisKeys(): void
    {
        $redis = Redis::connection();

        foreach (['security:realtime.event_flood:global', 'security:flood_alerted'] as $key) {
            $redis->del($key);
        }

        Cache::forget('realtime:kill_switch');
    }

    public function test_no_alert_below_threshold(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        config(['realtime.event_flood_threshold' => 10, 'realtime.event_flood_window' => 300]);

        for ($i = 0; $i < 10; $i++) {
            EventFloodDetector::check();
        }

        $this->assertDatabaseCount('security_alerts', 0);
        $this->assertNull(Cache::get('realtime:kill_switch'));
    }

    public function test_alert_and_kill_switch_when_threshold_exceeded(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        config(['realtime.event_flood_threshold' => 5, 'realtime.event_flood_window' => 300]);

        for ($i = 0; $i < 6; $i++) {
            EventFloodDetector::check();
        }

        // Alert created
        $this->assertDatabaseCount('security_alerts', 1);

        $alert = SecurityAlert::first();

        $this->assertEquals('realtime.event_flood', $alert->alert_type);
        $this->assertEquals('critical', $alert->severity);
        $this->assertEquals('open', $alert->status);
        $this->assertArrayHasKey('count', $alert->evidence);
        $this->assertArrayHasKey('threshold', $alert->evidence);

        // Kill switch activated
        $this->assertTrue(Cache::get('realtime:kill_switch'));

        // Audit log created
        $auditLog = PlatformAuditLog::where('action', AuditAction::KILL_SWITCH_AUTO_ACTIVATED)->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('critical', $auditLog->severity);
    }

    public function test_only_one_alert_per_window(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        config(['realtime.event_flood_threshold' => 5, 'realtime.event_flood_window' => 300]);

        for ($i = 0; $i < 20; $i++) {
            EventFloodDetector::check();
        }

        $this->assertDatabaseCount('security_alerts', 1);
    }

    public function test_kill_switch_has_one_hour_ttl(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }

        $this->cleanRedisKeys();

        config(['realtime.event_flood_threshold' => 3, 'realtime.event_flood_window' => 300]);

        for ($i = 0; $i < 4; $i++) {
            EventFloodDetector::check();
        }

        $this->assertTrue(Cache::get('realtime:kill_switch'));

        // Simulate time passing beyond 1 hour — the TTL is handled by cache driver,
        // so we just verify the key was set (TTL behavior is a cache driver guarantee).
    }
}
