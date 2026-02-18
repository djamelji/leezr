<?php

namespace App\Core\Theme;

use App\Platform\Models\PlatformSetting;

/**
 * Resolves UI theme configuration for a given scope.
 *
 * Reads from platform_settings table, delegates defaults to ThemePayload VO.
 * Global strict mode: company uses platform settings (no override).
 */
class UIResolverService
{
    /**
     * Resolve the UI theme for the platform scope.
     */
    public static function forPlatform(): ThemePayload
    {
        $dbTheme = PlatformSetting::instance()->theme ?? [];
        $defaults = ThemePayload::defaults();

        return new ThemePayload(
            theme: $dbTheme['theme'] ?? $defaults->theme,
            skin: $dbTheme['skin'] ?? $defaults->skin,
            primaryColor: $dbTheme['primary_color'] ?? $defaults->primaryColor,
            primaryDarkenColor: $dbTheme['primary_darken_color'] ?? $defaults->primaryDarkenColor,
            layout: $dbTheme['layout'] ?? $defaults->layout,
            navCollapsed: $dbTheme['nav_collapsed'] ?? $defaults->navCollapsed,
            semiDark: $dbTheme['semi_dark'] ?? $defaults->semiDark,
            navbarBlur: $dbTheme['navbar_blur'] ?? $defaults->navbarBlur,
            contentWidth: $dbTheme['content_width'] ?? $defaults->contentWidth,
        );
    }

    /**
     * Resolve the UI theme for the company scope.
     * Global strict mode: delegates to platform.
     */
    public static function forCompany(): ThemePayload
    {
        return static::forPlatform();
    }
}
