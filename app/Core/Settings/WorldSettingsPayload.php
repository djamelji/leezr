<?php

namespace App\Core\Settings;

use App\Core\Markets\MarketResolver;

/**
 * Immutable value object representing the world/localization configuration.
 *
 * ADR-104: Now reads from MarketResolver (default market) instead of
 * platform_settings.world JSON column. Falls back to platform_settings
 * when no markets exist (migration safety).
 */
final class WorldSettingsPayload
{
    public function __construct(
        public readonly string $country,
        public readonly string $currency,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly string $dialCode,
    ) {}

    public static function defaults(): self
    {
        return new self(
            country: 'US',
            currency: 'USD',
            locale: 'en-US',
            timezone: 'America/New_York',
            dialCode: '+1',
        );
    }

    /**
     * Build from the default market (ADR-104) or platform_settings.world fallback.
     */
    public static function fromSettings(): self
    {
        $market = MarketResolver::resolveDefault();

        return new self(
            country: $market->key ?? 'US',
            currency: $market->currency ?? 'USD',
            locale: $market->locale ?? 'en-US',
            timezone: $market->timezone ?? 'America/New_York',
            dialCode: $market->dial_code ?? '+1',
        );
    }

    /**
     * Snake_case array for API response.
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'dial_code' => $this->dialCode,
        ];
    }
}
