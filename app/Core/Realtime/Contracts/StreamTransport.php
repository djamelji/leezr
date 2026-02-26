<?php

namespace App\Core\Realtime\Contracts;

/**
 * ADR-128: Transport abstraction for SSE event polling.
 *
 * Abstracts the Redis polling loop from the stream controller,
 * allowing future replacement with PubSubTransport under Octane
 * without modifying controller code.
 *
 * Current implementations:
 *  - PollingTransport (usleep + zrangebyscore — works with FPM/CGI)
 *
 * Future:
 *  - PubSubTransport (Redis SUBSCRIBE — requires Octane/Swoole long-lived workers)
 */
interface StreamTransport
{
    /**
     * Stream events from the given Redis key since the last timestamp.
     *
     * Returns an iterable of [string $json, float $score] pairs.
     * The transport decides how to poll (sleep-based, pub/sub, etc.).
     * Returns an empty array when no new events are available.
     *
     * @param string $redisKey     The Redis sorted set key to read from
     * @param float  $lastTimestamp The exclusive lower bound timestamp (score)
     *
     * @return iterable<array{json: string, score: float}>
     */
    public function poll(string $redisKey, float $lastTimestamp): iterable;

    /**
     * Sleep between polls.
     *
     * Under FPM this is usleep(1_000_000). Under Octane/PubSub
     * this may be a no-op (events arrive via subscription).
     */
    public function sleep(): void;
}
