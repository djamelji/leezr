<?php

namespace App\Core\Realtime\Transports;

use App\Core\Realtime\Contracts\StreamTransport;
use Illuminate\Support\Facades\Redis;

/**
 * ADR-128: Fast-poll transport for reduced latency under FPM.
 *
 * Same sorted-set polling logic as PollingTransport, but with
 * 100ms sleep instead of 1s — yielding ~10x lower delivery latency.
 *
 * Under FPM, a true Redis SUBSCRIBE is impossible (Predis blocks
 * the thread). This transport compensates by polling more frequently.
 *
 * Under Octane/Swoole, the poll() body can be replaced with a
 * coroutine-based SUBSCRIBE that drains into an SplQueue.
 */
class PubSubTransport implements StreamTransport
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
        usleep(100_000); // 100ms — 10x faster than PollingTransport
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
