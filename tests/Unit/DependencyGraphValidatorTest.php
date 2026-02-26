<?php

namespace Tests\Unit;

use App\Core\Modules\DependencyGraphValidator;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for DependencyGraphValidator (ADR-115).
 *
 * Tests:
 * - Cycle detection in the requires graph
 * - Pricing invariants (required modules cannot be addon-priced)
 * - Graph introspection
 */
class DependencyGraphValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    // ─── Cycle detection ────────────────────────────────────

    public function test_no_cycles_in_current_graph(): void
    {
        // Should not throw
        DependencyGraphValidator::detectCycles();
        $this->assertTrue(true);
    }

    public function test_validate_runs_without_error(): void
    {
        // Full validation (cycles + pricing) should pass for current manifests
        DependencyGraphValidator::validate();
        $this->assertTrue(true);
    }

    // ─── Graph introspection ────────────────────────────────

    public function test_graph_returns_modules_with_requires(): void
    {
        $graph = DependencyGraphValidator::graph();

        $this->assertIsArray($graph);

        // At least the logistics modules should have requires
        $this->assertArrayHasKey('logistics_tracking', $graph);
        $this->assertContains('logistics_shipments', $graph['logistics_tracking']);
    }

    public function test_graph_omits_modules_without_requires(): void
    {
        $graph = DependencyGraphValidator::graph();

        // logistics_shipments has no requires
        $this->assertArrayNotHasKey('logistics_shipments', $graph);
    }

    // ─── Pricing invariants ─────────────────────────────────

    public function test_pricing_invariant_passes_for_included_required_module(): void
    {
        // Ensure logistics_shipments (required by others) is not addon-priced
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'included']);

        // Should not throw
        DependencyGraphValidator::validatePricingInvariants();
        $this->assertTrue(true);
    }

    public function test_pricing_invariant_fails_for_addon_required_module(): void
    {
        // Set logistics_shipments (required by tracking, fleet, analytics) to addon pricing
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'addon']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pricing_mode.*addon/i');

        DependencyGraphValidator::validatePricingInvariants();
    }

    public function test_pricing_invariant_ok_for_internal_required_module(): void
    {
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'internal']);

        // Should not throw — internal is allowed
        DependencyGraphValidator::validatePricingInvariants();
        $this->assertTrue(true);
    }
}
