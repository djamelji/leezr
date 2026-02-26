<?php

namespace App\Core\Security;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * ADR-129: Security anomaly detector.
 *
 * Uses Redis sliding window counters to detect threshold breaches.
 * When a threshold is exceeded, raises a SecurityAlert and publishes
 * a realtime security event.
 *
 * This service NEVER blocks the request — all operations are fire-and-forget.
 */
class SecurityDetector
{
    /**
     * Check if an alert type's threshold has been breached for a given actor key.
     *
     * @param string      $alertType One of AlertTypeRegistry keys
     * @param string      $actorKey  Unique key (e.g. IP, user_id, "company:{id}")
     * @param int|null    $companyId Company scope (nullable for platform-level)
     * @param int|null    $actorId   User/admin who triggered the action
     * @param array       $evidence  Additional evidence data
     */
    public static function check(
        string $alertType,
        string $actorKey,
        ?int $companyId = null,
        ?int $actorId = null,
        array $evidence = [],
    ): void {
        try {
            $definition = AlertTypeRegistry::get($alertType);

            if (!$definition) {
                return;
            }

            $redisKey = "leezr:security:{$alertType}:{$actorKey}";
            $redis = Redis::connection();

            // INCR counter with TTL = window
            $count = $redis->incr($redisKey);

            if ($count === 1) {
                $redis->expire($redisKey, $definition['window']);
            }

            // Threshold not exceeded — no alert
            if ($count <= $definition['threshold']) {
                return;
            }

            // Only raise once per window (threshold + 1)
            if ($count > $definition['threshold'] + 1) {
                return;
            }

            self::raise($alertType, $definition['severity'], $companyId, $actorId, array_merge($evidence, [
                'actor_key' => $actorKey,
                'count' => $count,
                'threshold' => $definition['threshold'],
                'window_seconds' => $definition['window'],
            ]));
        } catch (\Throwable $e) {
            // Never block the request
            Log::warning('[security] detector check failed', [
                'alert_type' => $alertType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Raise a security alert: DB insert + realtime publish.
     */
    private static function raise(
        string $alertType,
        string $severity,
        ?int $companyId,
        ?int $actorId,
        array $evidence,
    ): void {
        $alert = SecurityAlert::create([
            'alert_type' => $alertType,
            'severity' => $severity,
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'evidence' => $evidence,
            'status' => 'open',
            'created_at' => now(),
        ]);

        Log::channel('single')->warning('[security] alert raised', [
            'alert_id' => $alert->id,
            'alert_type' => $alertType,
            'severity' => $severity,
            'company_id' => $companyId,
            'actor_id' => $actorId,
        ]);

        // Publish realtime security event
        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::security('security.alert', $companyId, [
                    'alert_id' => $alert->id,
                    'alert_type' => $alertType,
                    'severity' => $severity,
                    'status' => 'open',
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[security] realtime publish failed', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
