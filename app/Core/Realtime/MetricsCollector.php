<?php

namespace App\Core\Realtime;

use Illuminate\Support\Facades\Redis;

/**
 * ADR-127: Collects realtime event metrics in Redis.
 *
 * Tracks event counts by topic+category, publish latency,
 * and delivery latency. All data is stored in Redis hashes
 * for efficient aggregation.
 */
class MetricsCollector
{
    private const METRICS_KEY = 'leezr:realtime:metrics';
    private const LATENCY_KEY_PREFIX = 'leezr:realtime:latency';
    private const MAX_LATENCY_SAMPLES = 1000;

    /**
     * Increment event counter for a topic+category pair.
     */
    public static function incrementEvent(string $topic, string $category): void
    {
        try {
            self::redis()->hincrby(self::METRICS_KEY, "events_total:{$topic}:{$category}", 1);
        } catch (\Throwable) {
            // Non-critical — skip silently
        }
    }

    /**
     * Record publish latency (time from controller to Redis write).
     */
    public static function recordPublishLatency(float $ms): void
    {
        self::appendLatency('publish', $ms);
    }

    /**
     * Record delivery latency (time from Redis write to SSE send).
     */
    public static function recordDeliveryLatency(float $ms): void
    {
        self::appendLatency('delivery', $ms);
    }

    /**
     * Get all metric counters.
     *
     * @return array<string, int>
     */
    public static function getMetrics(): array
    {
        try {
            $raw = self::redis()->hgetall(self::METRICS_KEY);

            return array_map('intval', $raw ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get latency statistics for a type ('publish' or 'delivery').
     *
     * @return array{count: int, min: float, max: float, avg: float, p50: float, p95: float, p99: float}
     */
    public static function getLatencyStats(string $type): array
    {
        try {
            $key = self::LATENCY_KEY_PREFIX.':'.$type;
            $raw = self::redis()->zrangebyscore($key, '-inf', '+inf', ['withscores' => true]);

            if (empty($raw)) {
                return ['count' => 0, 'min' => 0, 'max' => 0, 'avg' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0];
            }

            // Values are stored as scores (the member is a unique timestamp key)
            $values = array_values($raw);
            sort($values);

            $count = count($values);
            $sum = array_sum($values);

            return [
                'count' => $count,
                'min' => round($values[0], 2),
                'max' => round($values[$count - 1], 2),
                'avg' => round($sum / $count, 2),
                'p50' => round($values[(int) ($count * 0.50)], 2),
                'p95' => round($values[min($count - 1, (int) ($count * 0.95))], 2),
                'p99' => round($values[min($count - 1, (int) ($count * 0.99))], 2),
            ];
        } catch (\Throwable) {
            return ['count' => 0, 'min' => 0, 'max' => 0, 'avg' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0];
        }
    }

    /**
     * Reset all metrics.
     */
    public static function reset(): void
    {
        try {
            $conn = self::redis();
            $conn->del(self::METRICS_KEY);
            $conn->del(self::LATENCY_KEY_PREFIX.':publish');
            $conn->del(self::LATENCY_KEY_PREFIX.':delivery');
        } catch (\Throwable) {
            // Non-critical
        }
    }

    private static function appendLatency(string $type, float $ms): void
    {
        try {
            $key = self::LATENCY_KEY_PREFIX.':'.$type;
            $conn = self::redis();

            // Member = unique key (microtime), score = latency value
            $conn->zadd($key, $ms, microtime(true).':'.$type);

            // Trim to last N samples
            $count = $conn->zcard($key);
            if ($count > self::MAX_LATENCY_SAMPLES) {
                $conn->zremrangebyrank($key, 0, $count - self::MAX_LATENCY_SAMPLES - 1);
            }
        } catch (\Throwable) {
            // Non-critical
        }
    }

    private static function redis()
    {
        return Redis::connection(config('realtime.redis_connection', 'default'));
    }
}
