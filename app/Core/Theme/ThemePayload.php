<?php

namespace App\Core\Theme;

/**
 * Immutable value object representing the UI theme configuration
 * delivered to the frontend via /me and /login responses.
 */
final class ThemePayload
{
    public function __construct(
        public readonly string $theme,
        public readonly string $skin,
        public readonly string $primaryColor,
        public readonly string $primaryDarkenColor,
        public readonly string $layout,
        public readonly bool $navCollapsed,
        public readonly bool $semiDark,
        public readonly bool $navbarBlur,
        public readonly string $contentWidth,
    ) {}

    public static function defaults(): self
    {
        // ADR-332: Read platform colors from PlatformSetting when available.
        // Uses first() instead of instance() to avoid singleton violation in tests.
        $primary = '#7367F0';
        $darken = '#675DD8';

        try {
            $settings = \App\Platform\Models\PlatformSetting::query()->first();

            if ($settings) {
                $primary = $settings->theme['primary_color'] ?? $primary;
                $darken = $settings->theme['primary_darken_color'] ?? $darken;
            }
        } catch (\Throwable) {
            // During migrations or tests without DB — use hardcoded fallback
        }

        return new self(
            theme: 'system',
            skin: 'default',
            primaryColor: $primary,
            primaryDarkenColor: $darken,
            layout: 'vertical',
            navCollapsed: false,
            semiDark: false,
            navbarBlur: true,
            contentWidth: 'boxed',
        );
    }

    public function toArray(): array
    {
        return [
            'theme' => $this->theme,
            'skin' => $this->skin,
            'primary_color' => $this->primaryColor,
            'primary_darken_color' => $this->primaryDarkenColor,
            'layout' => $this->layout,
            'nav_collapsed' => $this->navCollapsed,
            'semi_dark' => $this->semiDark,
            'navbar_blur' => $this->navbarBlur,
            'content_width' => $this->contentWidth,
        ];
    }
}
