<?php

namespace App\Core\Navigation;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;

/**
 * Collects header widgets from active modules, filtered by permissions.
 *
 * Mirrors NavBuilder but for header toolbar widgets instead of sidebar navigation.
 * Each module can declare headerWidgets in its Capabilities.
 *
 * Pipeline:
 *  1. Collect manifests → filter active + globally enabled
 *  2. FlatMap headerWidgets from capabilities
 *  3. Filter by permissions (null = bypass for owner/super_admin)
 *  4. Sort by sortOrder
 *  5. Return flat array of widget descriptors
 */
class HeaderWidgetBuilder
{
    /**
     * Build header widgets for admin (platform) scope.
     *
     * Collects from ALL module scopes (admin + company) since platform admins
     * have global access. This ensures company-scoped widgets like core.theme
     * appear on the platform navbar without hardcoding.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (super_admin).
     * @return array  [['key', 'component', 'sortOrder']]
     */
    public static function forAdmin(?array $permissions = null): array
    {
        $manifests = collect(ModuleRegistry::definitions())
            ->filter(fn (ModuleManifest $m) => ModuleGate::isEnabledGlobally($m->key));

        return static::build($manifests->all(), $permissions);
    }

    /**
     * Build header widgets for company scope.
     *
     * Collects from company-scoped active modules only.
     * Filters by module activation (ModuleGate::isActive) + permissions.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (owner).
     * @return array  [['key', 'component', 'sortOrder']]
     */
    public static function forCompany(Company $company, ?array $permissions = null): array
    {
        $manifests = collect(ModuleRegistry::forScope('company'))
            ->filter(fn (ModuleManifest $m) => ModuleGate::isActive($company, $m->key));

        return static::build($manifests->all(), $permissions);
    }

    /**
     * Core pipeline — same for both scopes.
     *
     * @param  array<string, ModuleManifest>  $manifests
     * @param  array|null  $permissions
     * @return array
     */
    private static function build(array $manifests, ?array $permissions): array
    {
        // 1. FlatMap headerWidgets from capabilities
        $widgets = collect($manifests)
            ->flatMap(fn (ModuleManifest $m) => $m->capabilities->headerWidgets)
            ->values();

        // 2. Filter by permissions (null = bypass)
        if ($permissions !== null) {
            $permissionSet = array_flip($permissions);
            $widgets = $widgets->filter(function (array $widget) use ($permissionSet) {
                if (empty($widget['permission'])) {
                    return true;
                }

                return isset($permissionSet[$widget['permission']]);
            });
        }

        // 3. Sort by sortOrder
        $widgets = $widgets->sortBy('sortOrder')->values();

        // 4. Clean output (only send what frontend needs)
        return $widgets->map(fn (array $w) => [
            'key' => $w['key'],
            'component' => $w['component'],
            'sortOrder' => $w['sortOrder'],
        ])->all();
    }
}
