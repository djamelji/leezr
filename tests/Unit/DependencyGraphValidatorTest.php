<?php

namespace Tests\Unit;

use App\Core\Modules\DependencyGraphValidator;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for DependencyGraphValidator (ADR-115/206).
 *
 * Tests:
 * - Cycle detection in the requires graph
 * - Graph introspection
 *
 * ADR-206: Pricing invariants moved to ModulePricingPolicy.
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
        // Full validation (cycles only since ADR-206) should pass
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
}
