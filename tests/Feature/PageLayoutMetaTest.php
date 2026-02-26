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
