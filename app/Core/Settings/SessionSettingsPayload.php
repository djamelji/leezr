<?php

namespace App\Core\Settings;

use App\Platform\Models\PlatformSetting;

/**
 * Immutable value object representing the session governance configuration.
 * Stored in platform_settings.session JSON column.
 */
final class SessionSettingsPayload
{
    public function __construct(
        public readonly int $idleTimeout,
        public readonly int $warningThreshold,
        public readonly int $heartbeatInterval,
        public readonly bool $rememberMeEnabled,
        public readonly int $rememberMeDuration,
    ) {}

    public static function defaults(): self
    {
        return new self(
            idleTimeout: 120,
            warningThreshold: 5,
            heartbeatInterval: 10,
            rememberMeEnabled: false,
            rememberMeDuration: 43200,
        );
    }

    /**
     * Build from platform_settings DB row, merging over defaults.
     */
    public static function fromSettings(): self
    {
        $db = PlatformSetting::instance()->session ?? [];
        $d = self::defaults();

        return new self(
            idleTimeout: $db['idle_timeout'] ?? $d->idleTimeout,
            warningThreshold: $db['warning_threshold'] ?? $d->warningThreshold,
            heartbeatInterval: $db['heartbeat_interval'] ?? $d->heartbeatInterval,
            rememberMeEnabled: $db['remember_me_enabled'] ?? $d->rememberMeEnabled,
            rememberMeDuration: $db['remember_me_duration'] ?? $d->rememberMeDuration,
        );
    }

    /**
     * Snake_case array for DB storage.
     */
    public function toArray(): array
    {
        return [
            'idle_timeout' => $this->idleTimeout,
            'warning_threshold' => $this->warningThreshold,
            'heartbeat_interval' => $this->heartbeatInterval,
            'remember_me_enabled' => $this->rememberMeEnabled,
            'remember_me_duration' => $this->rememberMeDuration,
        ];
    }

    /**
     * Subset delivered to the frontend (only fields the governance composable needs).
     */
    public function toFrontendArray(): array
    {
        return [
            'idle_timeout' => $this->idleTimeout,
            'warning_threshold' => $this->warningThreshold,
            'heartbeat_interval' => $this->heartbeatInterval,
        ];
    }
}
