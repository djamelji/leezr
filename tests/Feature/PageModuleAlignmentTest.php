<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verification: frontend page↔module alignment.
 *
 * Ensures:
 *   - Every company module page declares meta.module matching the backend manifest
 *   - Every platform page with a permission uses a permission declared in a module
 *   - All module routeNames correspond to existing page files
 */
class PageModuleAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    /**
     * Every company module page that is module-gated on the backend
     * should declare meta.module in its Vue file.
     */
    public function test_company_module_pages_declare_meta_module(): void
    {
        $companyModules = ModuleRegistry::forScope('company');
        $missing = [];

        foreach ($companyModules as $key => $manifest) {
            foreach ($manifest->capabilities->routeNames as $routeName) {
                $pagePath = $this->routeNameToPagePath($routeName, 'company');

                if (!$pagePath || !file_exists($pagePath)) {
                    continue; // Page might not exist yet (stub modules)
                }

                $content = file_get_contents($pagePath);

                // Check if the page declares meta.module matching this module key
                if (!str_contains($content, "module: '{$key}'") && !str_contains($content, "module: \"{$key}\"")) {
                    $missing[] = "{$routeName} ({$pagePath}) should declare meta.module: '{$key}'";
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Company module pages missing meta.module declaration:\n" . implode("\n", $missing),
        );
    }

    /**
     * Every admin module page that is module-gated on the backend
     * should declare meta.module in its Vue file.
     */
    public function test_admin_module_pages_declare_meta_module(): void
    {
        $adminModules = ModuleRegistry::forScope('admin');
        $missing = [];

        foreach ($adminModules as $key => $manifest) {
            foreach ($manifest->capabilities->routeNames as $routeName) {
                $pagePath = $this->routeNameToPagePath($routeName, 'admin');

                if (!$pagePath || !file_exists($pagePath)) {
                    continue; // Page might not exist yet
                }

                $content = file_get_contents($pagePath);

                if (!str_contains($content, "module: '{$key}'") && !str_contains($content, "module: \"{$key}\"")) {
                    $missing[] = "{$routeName} ({$pagePath}) should declare meta.module: '{$key}'";
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Admin module pages missing meta.module declaration:\n" . implode("\n", $missing),
        );
    }

    /**
     * Every platform module permission must be declared in a module manifest.
     */
    public function test_platform_page_permissions_are_declared_in_modules(): void
    {
        // Collect all declared permissions from platform modules
        $declaredPermissions = [];

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            foreach ($manifest->permissions as $perm) {
                $declaredPermissions[] = $perm['key'];
            }
        }

        // Scan platform page files for permission declarations
        $platformPages = glob(base_path('resources/js/pages/platform/**/*.vue'));
        $platformPagesFlat = glob(base_path('resources/js/pages/platform/*.vue'));
        $allPages = array_merge($platformPages ?: [], $platformPagesFlat ?: []);

        $violations = [];

        foreach ($allPages as $file) {
            $content = file_get_contents($file);

            if (preg_match("/permission:\s*['\"]([^'\"]+)['\"]/", $content, $match)) {
                $permission = $match[1];

                if (!in_array($permission, $declaredPermissions, true)) {
                    $violations[] = basename($file) . " uses permission '{$permission}' which is not declared in any platform module";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Platform pages reference undeclared permissions:\n" . implode("\n", $violations),
        );
    }

    /**
     * Module routeNames should correspond to actual page files.
     */
    public function test_module_route_names_have_corresponding_pages(): void
    {
        $orphanRoutes = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            $scope = $manifest->scope;

            foreach ($manifest->capabilities->routeNames as $routeName) {
                $pagePath = $this->routeNameToPagePath($routeName, $scope);

                if (!$pagePath) {
                    $orphanRoutes[] = "{$key}: routeName '{$routeName}' could not be resolved to a page path";
                    continue;
                }

                if (!file_exists($pagePath)) {
                    $orphanRoutes[] = "{$key}: routeName '{$routeName}' → expected page at {$pagePath} but file not found";
                }
            }
        }

        $this->assertEmpty(
            $orphanRoutes,
            "Module routeNames without corresponding page files:\n" . implode("\n", $orphanRoutes),
        );
    }

    /**
     * Convert a route name to an expected page file path.
     *
     * Convention: unplugin-vue-router generates route names from file paths.
     * Example: 'platform-companies' → resources/js/pages/platform/companies/index.vue
     *          'platform-companies-id' → resources/js/pages/platform/companies/[id].vue
     *          'company-shipments' → resources/js/pages/company/shipments/index.vue
     */
    private function routeNameToPagePath(string $routeName, string $scope): ?string
    {
        $base = base_path('resources/js/pages');

        // Map of known route name → file path overrides (non-standard naming)
        $overrides = [
            'platform' => $base . '/platform/index.vue',
            'platform-international-tab' => $base . '/platform/international/[tab].vue',
            'platform-settings-tab' => $base . '/platform/settings/[tab].vue',
            'platform-markets-key' => $base . '/platform/markets/[key].vue',
            'platform-modules-key' => $base . '/platform/modules/[key].vue',
            'platform-plans-key' => $base . '/platform/plans/[key].vue',
            'platform-companies-id' => $base . '/platform/companies/[id].vue',
            'platform-jobdomains-id' => $base . '/platform/jobdomains/[id].vue',
            'platform-users-id' => $base . '/platform/users/[id].vue',
            'platform-company-users' => $base . '/platform/company/users.vue',
            'company-shipments' => $base . '/company/shipments/index.vue',
            'company-shipments-create' => $base . '/company/shipments/create.vue',
            'company-shipments-id' => $base . '/company/shipments/[id].vue',
            'company-my-deliveries' => $base . '/company/my-deliveries/index.vue',
            'company-my-deliveries-id' => $base . '/company/my-deliveries/[id].vue',
            'company-members' => $base . '/company/members/index.vue',
            'company-members-id' => $base . '/company/members/[id].vue',
            'company-settings' => $base . '/company/settings.vue',
            'company-modules' => $base . '/company/modules/index.vue',
            'company-plan' => $base . '/company/plan.vue',
            'company-billing-tab' => $base . '/company/billing/[tab].vue',
            'company-roles' => $base . '/company/roles.vue',
        ];

        if (isset($overrides[$routeName])) {
            return $overrides[$routeName];
        }

        // Generic resolution: replace scope prefix + dashes with path
        // Scope 'admin' maps to 'platform' directory and 'platform-' route prefix
        $dir = $scope === 'admin' ? 'platform' : $scope;
        $prefix = $dir . '-';

        if (!str_starts_with($routeName, $prefix)) {
            return null;
        }

        $rest = substr($routeName, strlen($prefix));
        $path = str_replace('-', '/', $rest);

        // Try as directory with index.vue
        $indexPath = $base . '/' . $dir . '/' . $path . '/index.vue';
        if (file_exists($indexPath)) {
            return $indexPath;
        }

        // Try as flat file
        $flatPath = $base . '/' . $dir . '/' . $path . '.vue';
        if (file_exists($flatPath)) {
            return $flatPath;
        }

        return $indexPath; // Return expected path even if not found
    }
}
