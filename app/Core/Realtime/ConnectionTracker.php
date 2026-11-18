<?php

namespace App\Core\Realtime;

use Illuminate\Support\Facades\Redis;

/**
 * ADR-127: Tracks active SSE connections via Redis counters.
 *
 * Enforces per-company and global limits. Uses atomic INCR/DECR
 * operations with TTL safety nets to prevent counter leaks.
 */
class ConnectionTracker
{
    private const KEY_PREFIX = 'leezr:realtime:stream';

    private const ACTIVE_TTL = 600; // 10min safety TTL on active keys

    /**
     * Register a new connection. Returns false if limit exceeded.
     */
    public static function connect(int $userId, int $companyId, ?string $ip = null): bool
    {
        try {
            $conn = self::redis();
        } catch (\Throwable) {
            return true; // Allow connection if Redis is unavailable
        }

        $activeKey = self::activeKey($userId, $companyId);
        $streamsKey = self::streamsKey($userId, $companyId);
        // ADR-826 : the user may hold several streams at once (a reload opens the new stream before the server
        // notices the old one is gone — up to one heartbeat ; several tabs). Streams are reference-counted per
        // user and company : the user counts once against the limits while at least one stream is alive, and is
        // only unregistered when the LAST stream ends.
        // ADR-827 : the reference count is taken with ONE atomic INCR — exactly one of several simultaneous
        // streams of the same user sees 1 and counts the user (the former read-then-increment let every
        // simultaneous stream count it, and the counters leaked n-1 per burst until every new stream was refused).
        $streams = (int) $conn->incr($streamsKey);
        $conn->expire($streamsKey, self::ACTIVE_TTL);
        $isNewUser = $streams === 1;
        $release = function () use ($conn, $streamsKey) {
            if ((int) $conn->decr($streamsKey) <= 0) {
                $conn->del($streamsKey);
            }
        };

        // Only check limits for NEW connections (not reconnects from same user)
        if ($isNewUser) {
            $maxPerCompany = config('realtime.max_streams_per_company', 100);
            $maxGlobal = config('realtime.max_streams_global', 500);

            if ((int) $conn->get(self::companyCountKey($companyId)) >= $maxPerCompany
                || (int) $conn->get(self::globalCountKey()) >= $maxGlobal) {
                $release();

                return false;
            }
        }

        // ADR-827 : every open stream holds one PHP worker for its whole life — the per-user limits above do
        // not bound that (one user, several tabs). Optional cap on the number of STREAMS, sized from the pool.
        $streamsTotal = (int) $conn->incr(self::streamsTotalKey());
        $conn->expire(self::streamsTotalKey(), self::ACTIVE_TTL);
        $maxStreams = (int) config('realtime.max_concurrent_streams', 0);
        if ($maxStreams > 0 && $streamsTotal > $maxStreams) {
            $conn->decr(self::streamsTotalKey());
            $release();

            return false;
        }

        // Register/refresh connection
        $conn->set($activeKey, json_encode([
            'user_id' => $userId,
            'company_id' => $companyId,
            'ip' => $ip,
            'connected_at' => now()->toIso8601String(),
        ]));
        $conn->expire($activeKey, self::ACTIVE_TTL);

        // Only increment counters for genuinely new connections
        if ($isNewUser) {
            $conn->incr(self::companyCountKey($companyId));
            $conn->expire(self::companyCountKey($companyId), self::ACTIVE_TTL);

            $conn->incr(self::globalCountKey());
            $conn->expire(self::globalCountKey(), self::ACTIVE_TTL);
        }

        return true;
    }

    /**
     * Unregister a connection.
     */
    public static function disconnect(int $userId, int $companyId): void
    {
        try {
            $conn = self::redis();
        } catch (\Throwable) {
            return;
        }

        // ADR-827 : one stream fewer holding a worker
        if ((int) $conn->get(self::streamsTotalKey()) > 0) {
            $conn->decr(self::streamsTotalKey());
        }

        // ADR-826 : another stream of the same user is still alive → it stays registered and counted
        if ((int) $conn->decr(self::streamsKey($userId, $companyId)) > 0) {
            return;
        }
        $conn->del(self::streamsKey($userId, $companyId));
        $conn->del(self::activeKey($userId, $companyId));

        // Decrement but never below zero
        $companyKey = self::companyCountKey($companyId);
        if ((int) $conn->get($companyKey) > 0) {
            $conn->decr($companyKey);
        }

        $globalKey = self::globalCountKey();
        if ((int) $conn->get($globalKey) > 0) {
            $conn->decr($globalKey);
        }
    }

    /**
     * Get all active connections.
     *
     * @return array<array{user_id: int, company_id: int, ip: string|null, connected_at: string}>
     */
    public static function activeConnections(): array
    {
        try {
            $conn = self::redis();

            // SCAN match pattern needs the Redis prefix (predis doesn't add it)
            $prefix = config('database.redis.options.prefix', '');
            $pattern = $prefix.self::KEY_PREFIX.':active:*';
            $keys = [];

            // Use SCAN to avoid blocking
            $cursor = '0';
            do {
                $result = $conn->scan($cursor, ['match' => $pattern, 'count' => 100]);
                if ($result === false) {
                    break;
                }
                [$cursor, $found] = $result;
                $keys = array_merge($keys, $found);
            } while ($cursor !== '0');

            $connections = [];
            foreach ($keys as $key) {
                // Strip the prefix so the facade can find the key
                $strippedKey = $prefix ? substr($key, strlen($prefix)) : $key;
                $data = $conn->get($strippedKey);
                if ($data) {
                    $decoded = json_decode($data, true);
                    if ($decoded) {
                        $connections[] = $decoded;
                    }
                }
            }

            return $connections;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get connection counts by company.
     *
     * @return array<int, int> companyId => count
     */
    public static function connectionsByCompany(): array
    {
        $connections = self::activeConnections();
        $counts = [];

        foreach ($connections as $c) {
            $cid = $c['company_id'];
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Get global connection count.
     */
    public static function globalCount(): int
    {
        try {
            return (int) self::redis()->get(self::globalCountKey());
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function activeKey(int $userId, int $companyId): string
    {
        return self::KEY_PREFIX.":active:{$userId}:{$companyId}";
    }

    private static function streamsTotalKey(): string
    {
        return self::KEY_PREFIX.':count:streams';
    }

    /** ADR-827 — number of open streams (each one holds a PHP worker). */
    public static function streamsTotal(): int
    {
        try {
            return (int) self::redis()->get(self::streamsTotalKey());
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function streamsKey(int $userId, int $companyId): string
    {
        return self::KEY_PREFIX.":streams:{$userId}:{$companyId}";
    }

    private static function companyCountKey(int $companyId): string
    {
        return self::KEY_PREFIX.":count:{$companyId}";
    }

    private static function globalCountKey(): string
    {
        return self::KEY_PREFIX.':count:global';
    }

    private static function redis()
    {
        return Redis::connection(config('realtime.redis_connection', 'default'));
    }
}
