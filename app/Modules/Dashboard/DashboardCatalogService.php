<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-357 + ADR-369: Provides filtered widget catalogs.
 *
 * Filtering chain:
 *   1. audience (company/both)
 *   2. scope (company/both)
 *   3. module activation (ModuleGate)
 *   4. user permissions (widget.permissions() ⊆ userPermissions)
 *   5. archetype (optional further narrowing)
 *
 * Owner bypasses permission check (has all permissions implicitly).
 */
class DashboardCatalogService
{
    /**
     * Return catalog filtered for a specific user context.
     *
     * @param  Company      $company          The company context
     * @param  string|null  $archetype        Role archetype (null = no filtering)
     * @param  array        $userPermissions  User's company permission keys
     * @param  bool         $isOwner          Owner bypasses permission checks
     * @return array<WidgetManifest>
     */
    public static function forArchetype(
        Company $company,
        ?string $archetype = null,
        array $userPermissions = [],
        bool $isOwner = false,
    ): array {
        return array_values(array_filter(
            DashboardWidgetRegistry::all(),
            function (WidgetManifest $w) use ($company, $archetype, $userPermissions, $isOwner) {
                // Widget must target company audience
                if (!in_array($w->audience(), ['company', 'both'], true)) {
                    return false;
                }

                // Widget must support company scope
                if (!in_array($w->scope(), ['company', 'both'], true)) {
                    return false;
                }

                // Widget's module must be active for this company
                if (!ModuleGate::isActive($company, $w->module())) {
                    return false;
                }

                // Permission check — owner bypasses
                if (!$isOwner && $w->permissions()) {
                    foreach ($w->permissions() as $perm) {
                        if (!in_array($perm, $userPermissions, true)) {
                            return false;
                        }
                    }
                }

                // Archetype filtering
                if ($archetype !== null) {
                    $allowed = $w->archetypes();
                    if ($allowed !== null && !in_array($archetype, $allowed, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }
}
