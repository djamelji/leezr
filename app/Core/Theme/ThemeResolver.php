<?php

namespace App\Core\Theme;

use App\Core\Models\User;
use App\Platform\Models\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves user theme preference.
 *
 * ADR-159: Backend returns stored preference only.
 * 'system' → frontend resolves via prefers-color-scheme media query.
 */
class ThemeResolver
{
    public const VALID = ['light', 'dark', 'system'];

    public static function resolve(Authenticatable $user): string
    {
        $pref = $user->theme_preference ?? 'system';

        return in_array($pref, self::VALID, true) ? $pref : 'system';
    }
}
