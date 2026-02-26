<?php

namespace App\Core\Security;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * ADR-131: Automatic kill switch on event flood.
 *
 * Called on every realtime publish. Uses a Redis counter (TTL = window)
 * to track global event volume. When the threshold is exceeded:
 *   1. Activates the kill switch (Cache, 1 hour)
 *   2. Creates a SecurityAlert
 *   3. Logs an audit entry
 *   4. Emits a critical log line
 *
 * Guard: only one alert per window (Redis flag).
 */
class EventFloodDetector
{
    private const REDIS_KEY = 'security:realtime.event_flood:global';
    private const ALERT_GUARD_KEY = 'security:flood_alerted';

    public static function check(): void
    {
        try {
            $threshold = config('realtime.event_flood_threshold', 1000);
            $window = config('realtime.event_flood_window', 300);

            $redis = Redis::connection();

            $count = $redis->incr(self::REDIS_KEY);

            if ($count === 1) {
                $redis->expire(self::REDIS_KEY, $window);
            }

            if ($count <= $threshold) {
                return;
            }

            // Guard: only alert once per window
            if ($redis->exists(self::ALERT_GUARD_KEY)) {
                return;
            }

            $redis->setex(self::ALERT_GUARD_KEY, $window, '1');

            // 1. Activate kill switch (auto-expire after 1 hour)
            Cache::put('realtime:kill_switch', true, now()->addHour());

            // 2. Create security alert
            SecurityAlert::create([
                'alert_type' => 'realtime.event_flood',
                'severity' => 'critical',
                'evidence' => [
                    'count' => $count,
                    'threshold' => $threshold,
                    'window_seconds' => $window,
                    'action' => 'auto_kill_switch_activated',
                ],
                'status' => 'open',
                'created_at' => now(),
            ]);

            // 3. Audit log
            try {
                app(AuditLogger::class)->logPlatform(
                    AuditAction::KILL_SWITCH_AUTO_ACTIVATED,
                    'realtime',
                    null,
                    [
                        'severity' => 'critical',
                        'metadata' => [
                            'count' => $count,
                            'threshold' => $threshold,
                            'window' => $window,
                        ],
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('[security] flood audit log failed', ['error' => $e->getMessage()]);
            }

            // 4. Critical log line
            Log::critical('[security] Event flood — auto kill switch activated', [
                'count' => $count,
                'threshold' => $threshold,
                'window' => $window,
            ]);
        } catch (\Throwable $e) {
            // Never block the publish path
            Log::warning('[security] EventFloodDetector failed', ['error' => $e->getMessage()]);
        }
    }
}
