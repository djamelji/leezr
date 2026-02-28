<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-153: Nav hydration gate invariants.
 *
 * Prevents regression on the empty-sidebar-after-login fix.
 * The fix relies on 4 layers:
 *   A) Nav store: isHydrated(scope) getter (source of truth)
 *   B) Resources: features:nav & platform:nav are critical
 *   C) Guard: detects stale ready (isReady but nav not hydrated → reboot)
 *   D) Layout gate: sidebar items gated on navStore.*Loaded
 */
class NavHydrationGateTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    // B) Nav resources must be critical
    // ═══════════════════════════════════════════════════════

    public function test_nav_resources_are_critical(): void
    {
        $content = file_get_contents(
            base_path('resources/js/core/runtime/resources.js'),
        );

        // features:nav (company scope)
        $this->assertNavResourceCritical($content, 'features:nav', 'fetchCompanyNav');

        // platform:nav
        $this->assertNavResourceCritical($content, 'platform:nav', 'fetchPlatformNav');
    }

    // ═══════════════════════════════════════════════════════
    // C) Router guard imports navStore for stale-ready check
    // ═══════════════════════════════════════════════════════

    public function test_router_guard_uses_nav_store(): void
    {
        $content = file_get_contents(
            base_path('resources/js/plugins/1.router/guards.js'),
        );

        $this->assertStringContainsString(
            'useNavStore',
            $content,
            'Router guard must import useNavStore for stale-ready detection',
        );

        $this->assertStringContainsString(
            'isHydrated',
            $content,
            'Router guard must check navStore.isHydrated(scope) before allowing navigation',
        );
    }

    // ═══════════════════════════════════════════════════════
    // D) Layout components gate sidebar on nav hydration
    // ═══════════════════════════════════════════════════════

    public function test_layout_components_gate_nav_items(): void
    {
        $layouts = [
            'DefaultLayoutWithVerticalNav.vue' => 'companyLoaded',
            'DefaultLayoutWithHorizontalNav.vue' => 'companyLoaded',
            'PlatformLayoutWithVerticalNav.vue' => 'platformLoaded',
            'PlatformLayoutWithHorizontalNav.vue' => 'platformLoaded',
        ];

        $violations = [];

        foreach ($layouts as $file => $loadedFlag) {
            $path = base_path("resources/js/layouts/components/{$file}");

            if (! file_exists($path)) {
                $violations[] = "{$file} — file not found";

                continue;
            }

            $content = file_get_contents($path);

            if (! str_contains($content, 'useNavStore')) {
                $violations[] = "{$file} — must import useNavStore for hydration gate";
            }

            if (! str_contains($content, $loadedFlag)) {
                $violations[] = "{$file} — must gate nav items on navStore.{$loadedFlag}";
            }
        }

        $this->assertEmpty(
            $violations,
            "Layout components missing nav hydration gate (ADR-153):\n".implode("\n", $violations),
        );
    }

    // ═══════════════════════════════════════════════════════
    // A) Nav store has isHydrated getter
    // ═══════════════════════════════════════════════════════

    public function test_nav_store_has_hydration_getter(): void
    {
        $content = file_get_contents(
            base_path('resources/js/core/stores/nav.js'),
        );

        $this->assertStringContainsString(
            'isHydrated',
            $content,
            'Nav store must expose isHydrated getter as hydration source of truth',
        );
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Assert that a nav resource block in resources.js has critical: true.
     */
    private function assertNavResourceCritical(string $content, string $key, string $action): void
    {
        // Find the resource block containing the key
        $pattern = '/\{[^}]*key:\s*[\'"]' . preg_quote($key, '/') . '[\'"][^}]*\}/s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $content,
            "Resource declaration for '{$key}' not found in resources.js",
        );

        preg_match($pattern, $content, $matches);
        $block = $matches[0];

        $this->assertStringContainsString(
            'critical: true',
            $block,
            "Resource '{$key}' must be critical: true (ADR-153: ready must include nav hydration)",
        );
    }
}
