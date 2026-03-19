<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ADR-370: Frontend structural invariants for access pipeline.
 *
 * These tests verify frontend code properties that must hold:
 * - No hardcoded widget imports in dashboard.vue
 * - No hardcoded nav items in useCompanyNav.js
 * - Billing sub-routes have proper definePage with surface
 */
class FrontendAccessInvariantsTest extends TestCase
{
    /**
     * INV-FE-001: No hardcoded widget import in dashboard.vue.
     * All widgets should come from the pipeline-driven catalog.
     */
    public function test_inv_fe_001_no_hardcoded_widget_import_in_dashboard(): void
    {
        $content = file_get_contents(resource_path('js/pages/dashboard.vue'));

        $this->assertStringNotContainsString(
            'import PlanBadgeWidget',
            $content,
            'dashboard.vue should not import PlanBadgeWidget statically — use pipeline catalog instead.'
        );

        $this->assertStringNotContainsString(
            'import OnboardingWidget',
            $content,
            'dashboard.vue should not import OnboardingWidget statically — use pipeline catalog instead.'
        );
    }

    /**
     * INV-FE-002: No hardcoded nav item in useCompanyNav.js.
     * All nav items should come from the backend manifest.
     */
    #[Group('skip-phase-6')]
    public function test_inv_fe_002_no_hardcoded_nav_item_in_company_nav(): void
    {
        $this->markTestSkipped('Phase 6 — will be unskipped after Account Settings becomes manifest-driven.');
    }

    /**
     * INV-FE-003: Billing sub-routes must have definePage with surface: 'structure'.
     */
    public function test_inv_fe_003_billing_sub_routes_have_surface_structure(): void
    {
        $billingPages = [
            resource_path('js/pages/company/billing/invoices/[id].vue'),
            resource_path('js/pages/company/billing/pay.vue'),
        ];

        foreach ($billingPages as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);

            $this->assertStringContainsString(
                'definePage',
                $content,
                basename($file) . ' must have definePage() call.'
            );

            $this->assertStringContainsString(
                "surface: 'structure'",
                $content,
                basename($file) . ' must declare surface: \'structure\' in definePage meta.'
            );
        }
    }
}
