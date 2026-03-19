<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ADR-370: CI invariants for the access pipeline.
 *
 * These tests verify structural properties that must ALWAYS hold:
 * - Bundle keys reference valid modules
 * - Permissions referenced by navItems exist in catalog
 * - Widget permissions exist in catalog
 * - No route relies on manage-structure alone for sensitive data
 * - All billing routes require billing.manage
 *
 * Invariants marked "skip-phase-N" will be unskipped as each phase lands.
 */
class AccessPipelineInvariantsTest extends TestCase
{
    /**
     * INV-PERM-001: Each bundle key referenced in JobdomainRegistry
     * default_roles must exist in a module manifest.
     */
    public function test_inv_perm_001_bundle_keys_exist_in_module_manifests(): void
    {
        $validBundleKeys = ModuleRegistry::allBundleKeys();
        $errors = [];

        foreach (JobdomainRegistry::definitions() as $jdKey => $definition) {
            foreach ($definition['default_roles'] ?? [] as $roleSlug => $role) {
                foreach ($role['bundles'] ?? [] as $bundleKey) {
                    if (! in_array($bundleKey, $validBundleKeys, true)) {
                        $errors[] = "Jobdomain '{$jdKey}' role '{$roleSlug}' references unknown bundle '{$bundleKey}'";
                    }
                }
            }
        }

        $this->assertEmpty($errors, "Invalid bundle references:\n" . implode("\n", $errors));
    }

    /**
     * INV-PERM-002: Non-admin roles (is_administrative=false) must not
     * reference bundles flagged is_admin=true.
     */
    #[Group('skip-phase-5')]
    public function test_inv_perm_002_non_admin_roles_have_no_admin_bundles(): void
    {
        $this->markTestSkipped('Phase 5 — will be unskipped after preset reconciliation engine.');
    }

    /**
     * INV-PERM-003: Each permission referenced by a navItem must exist
     * in CompanyPermissionCatalog.
     */
    public function test_inv_perm_003_nav_item_permissions_exist_in_catalog(): void
    {
        $catalogKeys = CompanyPermissionCatalog::keys();
        $errors = [];

        foreach (ModuleRegistry::forScope('company') as $manifest) {
            foreach ($manifest->capabilities->navItems as $item) {
                $permission = $item['permission'] ?? null;
                if ($permission !== null && ! in_array($permission, $catalogKeys, true)) {
                    $errors[] = "Module '{$manifest->key}' navItem '{$item['key']}' references unknown permission '{$permission}'";
                }
            }
        }

        $this->assertEmpty($errors, "NavItem permission references not in catalog:\n" . implode("\n", $errors));
    }

    /**
     * INV-WIDGET-001: Each widget's permissions() must reference keys
     * that exist in CompanyPermissionCatalog.
     */
    public function test_inv_widget_001_widget_permissions_exist_in_catalog(): void
    {
        DashboardWidgetRegistry::boot();
        $catalogKeys = CompanyPermissionCatalog::keys();
        $errors = [];

        foreach (DashboardWidgetRegistry::all() as $widget) {
            // Skip platform-only widgets (they use platform permissions)
            if ($widget->audience() === 'platform') {
                continue;
            }

            foreach ($widget->permissions() as $perm) {
                if (! in_array($perm, $catalogKeys, true)) {
                    $errors[] = "Widget '{$widget->key()}' references unknown permission '{$perm}'";
                }
            }
        }

        $this->assertEmpty($errors, "Widget permission references not in catalog:\n" . implode("\n", $errors));
    }

    /**
     * INV-WIDGET-002: Widgets that are READ-ONLY (client-resolved)
     * should require READ permissions (*.view), not WRITE permissions (*.manage).
     */
    public function test_inv_widget_002_readonly_widgets_use_read_permissions(): void
    {
        DashboardWidgetRegistry::boot();

        // Modules with no separate READ permission — manage IS the access gate
        $noReadAlternative = $this->collectModulesWithoutReadPermission();

        $errors = [];

        foreach (DashboardWidgetRegistry::all() as $widget) {
            if ($widget->audience() === 'platform') {
                continue;
            }

            // Client-resolved widgets are read-only by definition
            if ($widget->resolution() !== 'client') {
                continue;
            }

            foreach ($widget->permissions() as $perm) {
                // Skip if this module has no READ alternative (e.g. billing.manage is the only billing perm)
                $prefix = explode('.', $perm)[0] ?? '';
                if (in_array($prefix, $noReadAlternative, true)) {
                    continue;
                }

                if (str_contains($perm, '.manage') || str_contains($perm, '.create') || str_contains($perm, '.delete')) {
                    $errors[] = "Read-only widget '{$widget->key()}' requires WRITE permission '{$perm}' — should use a READ permission (*.view)";
                }
            }
        }

        $this->assertEmpty($errors, "Read-only widgets with WRITE permissions:\n" . implode("\n", $errors));
    }

    /**
     * INV-MANAGE-001: No POST/PUT/DELETE company route should use
     * manage-structure without an accompanying use-permission.
     * Exception: PUT /dashboard/layout (user-scoped, not sensitive).
     */
    public function test_inv_manage_001_no_mutation_route_relies_on_manage_structure_alone(): void
    {
        $exceptions = [
            'PUT api/dashboard/layout',
        ];

        $errors = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Only company-scoped routes (prefixed api/company or api/billing etc.)
            if (! str_starts_with($uri, 'api/company') && ! preg_match('#^api/(billing|dashboard|modules|audit|shipments|my-deliveries|support|theme|profile|notifications|2fa|realtime|nav)#', $uri)) {
                continue;
            }

            $methods = $route->methods();
            $isMutation = ! empty(array_intersect(['POST', 'PUT', 'DELETE', 'PATCH'], $methods));

            if (! $isMutation) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $hasManageStructure = false;
            $hasUsePermission = false;

            foreach ($middleware as $mw) {
                if (str_contains($mw, 'manage-structure')) {
                    $hasManageStructure = true;
                }
                if (str_contains($mw, 'use-permission')) {
                    $hasUsePermission = true;
                }
            }

            if ($hasManageStructure && ! $hasUsePermission) {
                $methodStr = implode('|', array_intersect(['POST', 'PUT', 'DELETE', 'PATCH'], $methods));
                $routeKey = "{$methodStr} {$uri}";

                if (! in_array($routeKey, $exceptions, true)) {
                    $errors[] = "Route '{$routeKey}' uses manage-structure without use-permission";
                }
            }
        }

        $this->assertEmpty($errors, "Mutation routes with manage-structure but no use-permission:\n" . implode("\n", $errors));
    }

    /**
     * INV-BILLING-001: ALL /billing/* routes must require use-permission:billing.manage.
     */
    public function test_inv_billing_001_all_billing_routes_require_billing_manage(): void
    {
        $errors = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! preg_match('#billing/#', $uri) && ! str_ends_with($uri, '/billing')) {
                continue;
            }

            // Skip platform billing routes and webhook routes
            if (str_contains($uri, 'platform') || str_contains($uri, 'webhooks')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $hasBillingManage = false;

            foreach ($middleware as $mw) {
                if (str_contains($mw, 'use-permission,billing.manage') || str_contains($mw, 'use-permission:billing.manage')) {
                    $hasBillingManage = true;
                    break;
                }
            }

            if (! $hasBillingManage) {
                $methods = implode('|', array_intersect(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $route->methods()));
                $errors[] = "Route '{$methods} {$uri}' missing use-permission:billing.manage";
            }
        }

        $this->assertEmpty($errors, "Billing routes without billing.manage:\n" . implode("\n", $errors));
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Modules where no *.view permission exists — *.manage is the only access gate.
     * These are acceptable exceptions for INV-WIDGET-002.
     */
    private function collectModulesWithoutReadPermission(): array
    {
        $prefixes = [];

        foreach (CompanyPermissionCatalog::all() as $perm) {
            $prefix = explode('.', $perm['key'])[0] ?? '';
            $prefixes[$prefix] ??= ['has_view' => false, 'has_write' => false];

            if (str_contains($perm['key'], '.view')) {
                $prefixes[$prefix]['has_view'] = true;
            }
            if (str_contains($perm['key'], '.manage') || str_contains($perm['key'], '.create') || str_contains($perm['key'], '.delete')) {
                $prefixes[$prefix]['has_write'] = true;
            }
        }

        return array_keys(array_filter($prefixes, fn ($p) => $p['has_write'] && ! $p['has_view']));
    }

    private function collectAdminBundleKeys(): array
    {
        $adminKeys = [];

        foreach (ModuleRegistry::forScope('company') as $manifest) {
            foreach ($manifest->bundles as $bundle) {
                if (! empty($bundle['is_admin'])) {
                    $adminKeys[] = $bundle['key'];
                }
            }
        }

        return $adminKeys;
    }
}
