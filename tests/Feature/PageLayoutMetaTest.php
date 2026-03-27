<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * BMAD-UI-001: Verify layout meta declarations on all page files.
 *
 * Rules:
 *   - Platform pages (pages/platform/**) MUST declare layout: 'platform' and platform: true
 *   - Platform auth pages (login, forgot-password, reset-password) use layout: 'blank'
 *   - Company pages (pages/company/**) must NOT declare layout: 'platform'
 *   - Sub-components (_*.vue) are NOT pages and must NOT declare definePage()
 */
class PageLayoutMetaTest extends TestCase
{
    /**
     * Every platform page must declare layout: 'platform' and platform: true
     * (except auth pages which use layout: 'blank').
     */
    public function test_platform_pages_declare_layout_platform(): void
    {
        $pagesDir = base_path('resources/js/pages/platform');
        $platformPages = $this->collectPageFiles($pagesDir);

        $authPages = ['login.vue', 'forgot-password.vue', 'reset-password.vue'];
        $violations = [];

        foreach ($platformPages as $file) {
            $basename = basename($file);

            // Skip sub-components
            if (str_starts_with($basename, '_')) {
                continue;
            }

            $content = file_get_contents($file);

            // Auth pages use blank layout
            if (in_array($basename, $authPages, true)) {
                if (!str_contains($content, "layout: 'blank'") && !str_contains($content, 'layout: "blank"')) {
                    $violations[] = $this->relPath($file) . " — auth page should declare layout: 'blank'";
                }

                continue;
            }

            // All other platform pages must have layout: 'platform'
            if (!str_contains($content, "layout: 'platform'") && !str_contains($content, 'layout: "platform"')) {
                $violations[] = $this->relPath($file) . " — missing layout: 'platform'";
            }

            // Must also have platform: true
            if (!str_contains($content, 'platform: true')) {
                $violations[] = $this->relPath($file) . " — missing platform: true";
            }
        }

        $this->assertEmpty(
            $violations,
            "Platform pages with missing layout meta (BMAD-UI-001):\n" . implode("\n", $violations),
        );
    }

    /**
     * Company pages must NOT declare layout: 'platform' (they use default layout).
     */
    public function test_company_pages_do_not_declare_platform_layout(): void
    {
        $pagesDir = base_path('resources/js/pages/company');
        $companyPages = $this->collectPageFiles($pagesDir);

        $violations = [];

        foreach ($companyPages as $file) {
            $basename = basename($file);

            if (str_starts_with($basename, '_')) {
                continue;
            }

            $content = file_get_contents($file);

            if (str_contains($content, "layout: 'platform'") || str_contains($content, 'layout: "platform"')) {
                $violations[] = $this->relPath($file) . " — company page must not use layout: 'platform'";
            }

            if (str_contains($content, 'platform: true')) {
                $violations[] = $this->relPath($file) . " — company page must not declare platform: true";
            }
        }

        $this->assertEmpty(
            $violations,
            "Company pages with incorrect platform layout (BMAD-UI-001):\n" . implode("\n", $violations),
        );
    }

    /**
     * Sub-components (_*.vue) must NOT declare definePage().
     */
    public function test_sub_components_do_not_declare_define_page(): void
    {
        $dirs = [
            base_path('resources/js/pages/platform'),
            base_path('resources/js/pages/company'),
        ];

        $violations = [];

        foreach ($dirs as $dir) {
            foreach ($this->collectPageFiles($dir) as $file) {
                $basename = basename($file);

                if (!str_starts_with($basename, '_')) {
                    continue;
                }

                $content = file_get_contents($file);

                if (str_contains($content, 'definePage(') || str_contains($content, 'definePage (')) {
                    $violations[] = $this->relPath($file) . " — sub-component should not declare definePage()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Sub-components with definePage() (BMAD-UI-001):\n" . implode("\n", $violations),
        );
    }

    // ─── ADR-418: RBAC Frontend invariants ──────────────────────

    /**
     * ADR-418 Test 1: Company pages with a module (non-exempt) must declare meta.permission.
     */
    public function test_company_pages_with_module_must_have_permission(): void
    {
        $pagesDir = base_path('resources/js/pages/company');
        $companyPages = $this->collectPageFiles($pagesDir);

        // Modules that don't require permission (exempt)
        $exemptModules = [
            'core.notifications', // no backend permission, categories filtered server-side
            'core.dashboard',     // always accessible (ADR-149)
        ];

        // Pages exempt from permission requirement
        $exemptPages = [
            '403.vue',                    // EXEMPT_ERROR_PAGE
            'dashboard.vue',              // EXEMPT_NO_DEFINEPAGE (core.dashboard)
            'home.vue',                   // EXEMPT_NO_DEFINEPAGE (workspace home)
        ];

        $violations = [];

        foreach ($companyPages as $file) {
            $basename = basename($file);

            // Skip sub-components
            if (str_starts_with($basename, '_')) {
                continue;
            }

            // Skip explicitly exempt pages
            if (in_array($basename, $exemptPages, true)) {
                continue;
            }

            $content = file_get_contents($file);

            // Only check pages that have definePage with a module
            if (! preg_match('/definePage\s*\(/', $content)) {
                continue;
            }

            // Extract module from meta
            if (! preg_match("/module:\s*['\"]([^'\"]+)['\"]/", $content, $moduleMatch)) {
                continue; // No module declared — exempt (e.g. account-settings)
            }

            $module = $moduleMatch[1];

            if (in_array($module, $exemptModules, true)) {
                continue;
            }

            // Page has a module and is not exempt → must have meta.permission
            if (! preg_match("/permission:\s*['\"][^'\"]+['\"]/", $content)) {
                $violations[] = $this->relPath($file) . " — module '{$module}' but no meta.permission (ADR-418)";
            }
        }

        $this->assertEmpty(
            $violations,
            "Company pages with module but missing meta.permission (ADR-418):\n" . implode("\n", $violations),
        );
    }

    /**
     * ADR-418 Test 2: Boot resources with protected endpoints must have permission field.
     * Static registry — update when adding new protected boot resources.
     */
    public function test_boot_resources_with_protected_endpoints_have_permission_field(): void
    {
        $resourcesFile = base_path('resources/js/core/runtime/resources.js');
        $content = file_get_contents($resourcesFile);

        // Registry of boot resources that call permission-protected endpoints
        $protectedResources = [
            'tenant:jobdomain' => 'jobdomain.view',
        ];

        $violations = [];

        foreach ($protectedResources as $key => $expectedPermission) {
            // Find the resource block
            if (! str_contains($content, "key: '{$key}'")) {
                $violations[] = "{$key} — resource not found in resources.js";

                continue;
            }

            // Check permission declaration exists
            if (! str_contains($content, "permission: '{$expectedPermission}'")) {
                $violations[] = "{$key} — missing permission: '{$expectedPermission}' in ResourceDef";
            }
        }

        $this->assertEmpty(
            $violations,
            "Boot resources missing permission declaration (ADR-418):\n" . implode("\n", $violations),
        );
    }

    /**
     * ADR-418 Test 3: Job runtime must include permission gate before running.
     */
    public function test_job_runtime_has_permission_gate(): void
    {
        $jobFile = base_path('resources/js/core/runtime/job.js');
        $content = file_get_contents($jobFile);

        $this->assertStringContainsString(
            'this.resource.permission',
            $content,
            'job.js must check resource.permission before running (ADR-418)',
        );

        $this->assertStringContainsString(
            'job:skip-permission',
            $content,
            'job.js must log job:skip-permission when skipping (ADR-418)',
        );
    }

    /**
     * ADR-418 Test 4: Router guards must include company permission check.
     */
    public function test_router_guards_include_company_permission_check(): void
    {
        $guardsFile = base_path('resources/js/plugins/1.router/guards.js');
        $content = file_get_contents($guardsFile);

        // Must have both platform and company permission guards
        $this->assertStringContainsString(
            'to.meta.permission',
            $content,
            'guards.js must check to.meta.permission (ADR-418)',
        );

        // Company guard specifically — the "Permission guard — ADR-418" block
        $this->assertStringContainsString(
            'company403',
            $content,
            'guards.js must redirect to company403 on permission denied (ADR-418)',
        );
    }

    /**
     * ADR-418 Test 5: Document tabs with restricted permissions must use tab gating.
     */
    public function test_document_tabs_have_permission_gating(): void
    {
        $docPage = base_path('resources/js/pages/company/documents/[tab].vue');
        $content = file_get_contents($docPage);

        // Must declare tab permissions map
        $this->assertStringContainsString(
            'tabPermissions',
            $content,
            'documents/[tab].vue must declare tabPermissions map (ADR-418)',
        );

        // Must gate requests tab
        $this->assertStringContainsString(
            "requests: 'documents.manage'",
            $content,
            'documents/[tab].vue must gate requests tab with documents.manage (ADR-418)',
        );

        // Must gate settings tab
        $this->assertStringContainsString(
            "settings: 'documents.configure'",
            $content,
            'documents/[tab].vue must gate settings tab with documents.configure (ADR-418)',
        );
    }

    /**
     * Recursively collect all .vue files in a directory.
     */
    private function collectPageFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relPath(string $path): string
    {
        return str_replace(base_path('/') , '', $path);
    }
}
