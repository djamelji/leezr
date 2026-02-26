<?php

namespace App\Core\Security;

/**
 * ADR-129: Closed registry of security alert types.
 *
 * Each alert type defines a threshold, window (seconds), and severity.
 * The SecurityDetector uses this registry to determine when to raise alerts.
 */
final class AlertTypeRegistry
{
    /**
     * @var array<string, array{threshold: int, window: int, severity: string, description: string}>
     */
    private const TYPES = [
        'suspicious.login_attempts' => [
            'threshold' => 10,
            'window' => 300,      // 5 minutes
            'severity' => 'high',
            'description' => 'More than 10 failed login attempts in 5 minutes.',
        ],
        'mass.role_changes' => [
            'threshold' => 5,
            'window' => 120,      // 2 minutes
            'severity' => 'medium',
            'description' => 'More than 5 role changes in 2 minutes.',
        ],
        'abnormal.module_toggling' => [
            'threshold' => 3,
            'window' => 600,      // 10 minutes
            'severity' => 'medium',
            'description' => 'More than 3 module enable/disable cycles in 10 minutes.',
        ],
        'excessive.stream_connections' => [
            'threshold' => 10,
            'window' => 300,      // 5 minutes
            'severity' => 'high',
            'description' => 'More than 10 SSE connection attempts in 5 minutes.',
        ],
        'rapid.permission_flips' => [
            'threshold' => 3,
            'window' => 600,      // 10 minutes
            'severity' => 'medium',
            'description' => 'More than 3 permission changes in 10 minutes.',
        ],
        'bulk.member_removal' => [
            'threshold' => 5,
            'window' => 300,      // 5 minutes
            'severity' => 'high',
            'description' => 'More than 5 member removals in 5 minutes.',
        ],
        'unauthorized.access_pattern' => [
            'threshold' => 20,
            'window' => 300,      // 5 minutes
            'severity' => 'high',
            'description' => 'More than 20 forbidden responses in 5 minutes.',
        ],
        'realtime.event_flood' => [
            'threshold' => 1000,
            'window' => 300,      // 5 minutes
            'severity' => 'critical',
            'description' => 'Event flood detected — auto kill switch activated.',
        ],
    ];

    public static function get(string $type): ?array
    {
        return self::TYPES[$type] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public static function all(): array
    {
        return self::TYPES;
    }
}
