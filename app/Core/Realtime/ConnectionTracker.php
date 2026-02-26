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

        $maxPerCompany = config('realtime.max_streams_per_company', 100);
        $maxGlobal = config('realtime.max_streams_global', 500);

        // Check company limit
        $companyCount = (int) $conn->get(self::companyCountKey($companyId));
        if ($companyCount >= $maxPerCompany) {
            return false;
        }

        // Check global limit
        $globalCount = (int) $conn->get(self::globalCountKey());
        if ($globalCount >= $maxGlobal) {
            return false;
        }

        // Register connection
        $activeKey = self::activeKey($userId, $companyId);
        $conn->set($activeKey, json_encode([
            'user_id' => $userId,
            'company_id' => $companyId,
            'ip' => $ip,
            'connected_at' => now()->toIso8601String(),
        ]));
        $conn->expire($activeKey, self::ACTIVE_TTL);

        $conn->incr(self::companyCountKey($companyId));
        $conn->expire(self::companyCountKey($companyId), self::ACTIVE_TTL);

        $conn->incr(self::globalCountKey());
        $conn->expire(self::globalCountKey(), self::ACTIVE_TTL);

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
