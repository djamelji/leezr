<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Platform\Models\PlatformUser;

final class DashboardWidgetRegistry
{
    /** @var array<string, class-string<WidgetManifest>> */
    private static array $widgets = [];

    public static function register(string $widgetClass): void
    {
        /** @var WidgetManifest $instance */
        $instance = app($widgetClass);
        static::$widgets[$instance->key()] = $widgetClass;
    }

    /** @return array<WidgetManifest> */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), static::$widgets);
    }

    public static function find(string $key): ?WidgetManifest
    {
        $class = static::$widgets[$key] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * Return widgets visible to the given platform user (filtered by permissions + capabilities).
     *
     * @return array<WidgetManifest>
     */
    public static function catalogForUser(PlatformUser $user): array
    {
        return array_values(array_filter(
            static::all(),
            function (WidgetManifest $w) use ($user) {
                // Widget must target platform audience
                if (!in_array($w->audience(), ['platform', 'both'], true)) {
                    return false;
                }

                // Module must be globally enabled
                if (!ModuleGate::isEnabledGlobally($w->module())) {
                    return false;
                }

                foreach ($w->permissions() as $perm) {
                    if (!$user->hasPermission($perm)) {
                        return false;
                    }
                }

                foreach ($w->capabilities() as $cap) {
                    if (!$user->hasCapability($cap)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Return widgets visible for a company (filtered by audience + scope + module activation).
     *
     * @return array<WidgetManifest>
     */
    public static function catalogForCompany(Company $company): array
    {
        return array_values(array_filter(
            static::all(),
            function (WidgetManifest $w) use ($company) {
                // Widget must target company audience
                if (!in_array($w->audience(), ['company', 'both'], true)) {
                    return false;
                }

                // Widget must support company scope
                if (!in_array($w->scope(), ['company', 'both'], true)) {
                    return false;
                }

                // Widget's module must be active for this company
                return ModuleGate::isActive($company, $w->module());
            }
        ));
    }

    /**
     * Boot widgets via convention-based discovery.
     * Scans app/Modules/ * /Dashboard/widgets.php and app/Modules/ * / * /Dashboard/widgets.php.
     */
    public static function boot(): void
    {
        $modulesBase = app_path('Modules');

        $patterns = [
            $modulesBase . '/*/widgets.php',
            $modulesBase . '/*/Dashboard/widgets.php',
            $modulesBase . '/*/*/Dashboard/widgets.php',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $classes = require $file;

                foreach ((array) $classes as $class) {
                    static::register($class);
                }
            }
        }
    }

    /**
     * Filter a saved layout array, keeping only tiles whose widget key
     * exists in the registry AND whose module is currently enabled.
     *
     * Uses isActiveForScope() which handles both admin-scoped and
     * company-scoped modules correctly:
     *   - admin-scoped → isEnabledGlobally()
     *   - company-scoped → isActive($company, key)
     *
     * @return array Filtered layout tiles (re-indexed)
     */
    public static function filterLayout(array $tiles, ?Company $company = null): array
    {
        return array_values(array_filter($tiles, function (array $tile) use ($company) {
            $widget = static::find($tile['key'] ?? '');

            if (!$widget) {
                return false;
            }

            return ModuleGate::isActiveForScope($widget->module(), $company);
        }));
    }

    public static function clearCache(): void
    {
        static::$widgets = [];
    }
}
