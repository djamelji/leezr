<?php

namespace Tests\Feature;

use App\Core\Realtime\ConnectionTracker;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ADR-826 — a reload opens the new SSE stream before the server notices the old one is gone (it only learns it
 * on its next heartbeat write). The first ending stream must not unregister the live one.
 * Uses fictive ids on the configured Redis ; skipped when Redis is not reachable.
 */
class Adr826RealtimeConnectionTrackerTest extends TestCase
{
    private const C = 999101;

    private const U = 999102;

    private function conn()
    {
        return Redis::connection(config('realtime.redis_connection', 'default'));
    }

    private function cleanup(): void
    {
        foreach (['active:'.self::U.':'.self::C, 'streams:'.self::U.':'.self::C, 'count:'.self::C] as $k) {
            $this->conn()->del('leezr:realtime:stream:'.$k);
        }
    }

    private function state(): array
    {
        return [(int) $this->conn()->get('leezr:realtime:stream:count:'.self::C),
            collect(ConnectionTracker::activeConnections())->where('company_id', self::C)->count()];
    }

    public function test_overlapping_streams_of_one_user_stay_registered_until_the_last_ends(): void
    {
        try {
            $this->conn()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not reachable');
        }
        $this->cleanup();
        try {
            $this->assertTrue(ConnectionTracker::connect(self::U, self::C));
            $this->assertTrue(ConnectionTracker::connect(self::U, self::C), 'reload : new stream while the old is alive');
            $this->assertSame([1, 1], $this->state(), 'the user counts once');
            ConnectionTracker::disconnect(self::U, self::C);
            $this->assertSame([1, 1], $this->state(), 'old stream ends : the live one stays registered and counted');
            ConnectionTracker::disconnect(self::U, self::C);
            $this->assertSame([0, 0], $this->state(), 'last stream ends : unregistered');
            ConnectionTracker::disconnect(self::U, self::C);
            $this->assertSame([0, 0], $this->state(), 'an extra disconnect never goes below zero');
        } finally {
            $this->cleanup();
        }
    }
}
