<?php

namespace Tests\Unit;

use App\Core\Realtime\Adapters\SseRealtimePublisher;
use App\Core\Realtime\Transports\PubSubTransport;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ADR-128: PubSubTransport unit tests.
 */
class PubSubTransportTest extends TestCase
{
    private const TEST_KEY = 'test:realtime:pubsub_transport';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->del(self::TEST_KEY);
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not available');
        }
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->del(self::TEST_KEY);
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    public function test_poll_returns_events_from_sorted_set(): void
    {
        $conn = Redis::connection();
        $now = microtime(true);

        $conn->zadd(self::TEST_KEY, $now + 0.001, json_encode(['topic' => 'test.a']));
        $conn->zadd(self::TEST_KEY, $now + 0.002, json_encode(['topic' => 'test.b']));

        $transport = new PubSubTransport();
        $events = $transport->poll(self::TEST_KEY, $now);

        $events = is_array($events) ? $events : iterator_to_array($events);

        $this->assertCount(2, $events);
        $this->assertArrayHasKey('json', $events[0]);
        $this->assertArrayHasKey('score', $events[0]);

        $decoded = json_decode($events[0]['json'], true);
        $this->assertEquals('test.a', $decoded['topic']);
    }

    public function test_poll_returns_empty_when_no_events(): void
    {
        $transport = new PubSubTransport();
        $events = $transport->poll(self::TEST_KEY, microtime(true));

        $events = is_array($events) ? $events : iterator_to_array($events);

        $this->assertEmpty($events);
    }

    public function test_sleep_is_fast(): void
    {
        $transport = new PubSubTransport();

        $start = hrtime(true);
        $transport->sleep();
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

        // Should be ~100ms, definitely less than 500ms
        $this->assertLessThan(500, $elapsed, 'PubSubTransport sleep should be ~100ms');
        // And at least 50ms (not zero)
        $this->assertGreaterThan(50, $elapsed, 'PubSubTransport sleep should be at least 50ms');
    }

    public function test_graceful_on_redis_down(): void
    {
        // Use a connection name that doesn't exist to simulate Redis failure
        $transport = new PubSubTransport('nonexistent_connection_12345');

        $events = $transport->poll('any:key', microtime(true));
        $events = is_array($events) ? $events : iterator_to_array($events);

        $this->assertEmpty($events);
    }

    // ─── PubSub Channel Helper ──────────────────────────

    public function test_pubsub_channel_for_company(): void
    {
        config(['realtime.redis_prefix' => 'test:rt']);

        $channel = SseRealtimePublisher::pubsubChannelFor(42);

        $this->assertEquals('test:rt:pubsub:company:42', $channel);
    }

    public function test_pubsub_channel_for_platform(): void
    {
        config(['realtime.redis_prefix' => 'test:rt']);

        $channel = SseRealtimePublisher::pubsubChannelFor(null);

        $this->assertEquals('test:rt:pubsub:platform', $channel);
    }
}
