<?php

namespace App\Core\Navigation;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;

/**
 * Collects footer links from active modules, filtered by permissions.
 *
 * Mirrors HeaderWidgetBuilder but for footer links instead of header widgets.
 * Each module can declare footerLinks in its Capabilities.
 *
 * Pipeline:
 *  1. Collect manifests → filter active + scope-specific
 *  2. FlatMap footerLinks from capabilities
 *  3. Filter by permissions (null = bypass for owner/super_admin)
 *  4. Sort by sortOrder
 *  5. Return flat array of link descriptors
 */
class FooterLinkBuilder
{
    /**
     * Build footer links for admin (platform) scope.
     *
     * Collects only from admin-scope modules — strict scope separation.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (super_admin).
     * @return array  [['key', 'label', 'to', 'href', 'icon', 'sortOrder']]
     */
    public static function forAdmin(?array $permissions = null): array
    {
        $manifests = collect(ModuleRegistry::forScope('admin'))
            ->filter(fn (ModuleManifest $m) => ModuleGate::isEnabledGlobally($m->key));

        return static::build($manifests->all(), $permissions);
    }

    /**
     * Build footer links for company scope.
     *
     * Collects only from company-scope active modules.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (owner).
     * @return array  [['key', 'label', 'to', 'href', 'icon', 'sortOrder']]
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
        // 1. FlatMap footerLinks from capabilities
        $links = collect($manifests)
            ->flatMap(fn (ModuleManifest $m) => $m->capabilities->footerLinks)
            ->values();

        // 2. Filter by permissions (null = bypass)
        if ($permissions !== null) {
            $permissionSet = array_flip($permissions);
            $links = $links->filter(function (array $link) use ($permissionSet) {
                if (empty($link['permission'])) {
                    return true;
                }

                return isset($permissionSet[$link['permission']]);
            });
        }

        // 3. Sort by sortOrder
        $links = $links->sortBy('sortOrder')->values();

        // 4. Clean output (only send what frontend needs)
        return $links->map(fn (array $l) => [
            'key' => $l['key'],
            'label' => $l['label'],
            'to' => $l['to'] ?? null,
            'href' => $l['href'] ?? null,
            'icon' => $l['icon'] ?? null,
            'sortOrder' => $l['sortOrder'],
        ])->all();
    }
}
