<?php

namespace App\Core\Realtime\Transports;

use App\Core\Realtime\Contracts\StreamTransport;
use Illuminate\Support\Facades\Redis;

/**
 * ADR-128: FPM-compatible polling transport.
 *
 * Reads events from a Redis sorted set using ZRANGEBYSCORE.
 * Sleeps 1 second between polls (appropriate for FPM workers).
 *
 * Under Octane, replace with PubSubTransport that uses
 * Redis SUBSCRIBE for lower-latency delivery.
 */
class PollingTransport implements StreamTransport
{
    private $connection = null;

    public function __construct(
        private readonly string $redisConnection = 'default',
    ) {}

    /**
     * @inheritDoc
     */
    public function poll(string $redisKey, float $lastTimestamp): iterable
    {
        $conn = $this->getConnection();

        if ($conn === null) {
            return [];
        }

        $events = $conn->zrangebyscore(
            $redisKey,
            '('.$lastTimestamp, // exclusive lower bound
            '+inf',
            ['withscores' => true],
        );

        if (empty($events)) {
            return [];
        }

        $result = [];

        foreach ($events as $json => $score) {
            $result[] = ['json' => $json, 'score' => (float) $score];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function sleep(): void
    {
        usleep(1_000_000); // 1 second
    }

    private function getConnection()
    {
        if ($this->connection === null) {
            try {
                $this->connection = Redis::connection($this->redisConnection);
            } catch (\Throwable) {
                $this->connection = false;
            }
        }

        return $this->connection === false ? null : $this->connection;
    }
}
