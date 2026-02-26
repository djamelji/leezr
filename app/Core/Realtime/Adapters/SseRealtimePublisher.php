<?php

namespace App\Core\Realtime\Adapters;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Core\Realtime\MetricsCollector;
use App\Core\Security\EventFloodDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Publishes realtime events via Redis sorted sets.
 *
 * ADR-125: SSE Invalidation Engine — Phase 1.
 * ADR-126: Accepts EventEnvelope with category and ULID.
 *
 * Events are stored in a Redis sorted set (score = timestamp)
 * with a 2-minute auto-cleanup. SSE stream controllers poll
 * the set every ~1s and relay new events to browser clients.
 *
 * Channel pattern: {prefix}:company:{companyId}
 */
class SseRealtimePublisher implements RealtimePublisher
{
    /** Events older than this are garbage-collected. */
    private const EVENT_TTL_SECONDS = 120;

    /** Safety TTL on the Redis key itself (auto-expire if no events). */
    private const KEY_TTL_SECONDS = 300;

    public function publish(EventEnvelope $envelope): void
    {
        $key = self::keyFor($envelope->companyId);
        $startTime = hrtime(true);

        try {
            $conn = Redis::connection(config('realtime.redis_connection', 'default'));

            // Store event in sorted set (score = timestamp for chronological read)
            $conn->zadd($key, $envelope->timestamp, $envelope->toJson());

            // Garbage-collect old events (older than 2 minutes)
            $conn->zremrangebyscore($key, '-inf', (string) (microtime(true) - self::EVENT_TTL_SECONDS));

            // Safety TTL on the key itself
            $conn->expire($key, self::KEY_TTL_SECONDS);

            // ADR-126: Metrics increment
            $metricsKey = config('realtime.redis_prefix', 'leezr:realtime').':metrics';
            $conn->hincrby($metricsKey, "events_total:{$envelope->topic}:{$envelope->category->value}", 1);

            // ADR-128: Dual-write — PUBLISH for PubSubTransport listeners
            // Fire-and-forget: if nobody listens, the message is lost
            // (sorted set remains the durable source of truth)
            $conn->publish(
                self::pubsubChannelFor($envelope->companyId),
                $envelope->toJson(),
            );

            // Publish latency = time from method entry to Redis write complete
            $publishMs = (hrtime(true) - $startTime) / 1_000_000;
            MetricsCollector::recordPublishLatency($publishMs);

            // ADR-131: Check for event flood → auto kill switch
            EventFloodDetector::check();

            Log::debug('[realtime] publish', [
                'id' => $envelope->id,
                'topic' => $envelope->topic,
                'category' => $envelope->category->value,
                'company_id' => $envelope->companyId,
            ]);
        } catch (\Throwable $e) {
            // Redis down → degrade gracefully. Frontend falls back to polling.
            Log::warning('[realtime] publish failed — Redis unreachable', [
                'topic' => $envelope->topic,
                'category' => $envelope->category->value,
                'company_id' => $envelope->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the Redis key for a company's (or platform) event stream.
     */
    public static function keyFor(?int $companyId): string
    {
        $prefix = config('realtime.redis_prefix', 'leezr:realtime');

        if ($companyId === null) {
            return "{$prefix}:platform";
        }

        return "{$prefix}:company:{$companyId}";
    }

    /**
     * ADR-128: Build the Redis PubSub channel for dual-write PUBLISH.
     */
    public static function pubsubChannelFor(?int $companyId): string
    {
        $prefix = config('realtime.redis_prefix', 'leezr:realtime');

        if ($companyId === null) {
            return "{$prefix}:pubsub:platform";
        }

        return "{$prefix}:pubsub:company:{$companyId}";
    }
}
