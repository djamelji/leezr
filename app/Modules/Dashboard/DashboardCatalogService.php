<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-357: Provides filtered widget catalogs for a given archetype.
 *
 * Explicit inputs, no implicit request context — deterministic and testable.
 *
 * Separation from DashboardWidgetRegistry:
 *   Registry = static storage (scan, register, find)
 *   CatalogService = business filtering (archetype + company + modules)
 */
class DashboardCatalogService
{
    /**
     * Return catalog filtered for a specific archetype.
     *
     * @param  Company      $company    The company context
     * @param  string|null  $archetype  Role archetype (null = no archetype filtering)
     * @return array<WidgetManifest>
     */
    public static function forArchetype(Company $company, ?string $archetype = null): array
    {
        return array_values(array_filter(
            DashboardWidgetRegistry::all(),
            function (WidgetManifest $w) use ($company, $archetype) {
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
