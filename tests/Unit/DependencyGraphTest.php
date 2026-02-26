<?php

namespace Tests\Unit;

use App\Core\Modules\DependencyGraph;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for DependencyGraph introspection (ADR-116).
 *
 * Uses real manifests:
 *   logistics_tracking  requires [logistics_shipments]
 *   logistics_fleet     requires [logistics_shipments]
 *   logistics_analytics requires [logistics_shipments]
 *   logistics_shipments requires []
 */
class DependencyGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    // ─── requires() ─────────────────────────────────────────

    public function test_direct_requires(): void
    {
        $requires = DependencyGraph::requires('logistics_tracking');

        $this->assertContains('logistics_shipments', $requires);
        $this->assertCount(1, $requires);
    }

    public function test_empty_requires(): void
    {
        $requires = DependencyGraph::requires('logistics_shipments');

        $this->assertEmpty($requires);
    }

    public function test_requires_unknown_module_returns_empty(): void
    {
        $requires = DependencyGraph::requires('nonexistent_module');

        $this->assertEmpty($requires);
    }

    // ─── requiresClosure() ──────────────────────────────────

    public function test_transitive_closure_single_level(): void
    {
        $closure = DependencyGraph::requiresClosure('logistics_tracking');

        $this->assertContains('logistics_shipments', $closure);
        $this->assertNotContains('logistics_tracking', $closure);
    }

    public function test_transitive_closure_empty_for_no_requires(): void
    {
        $closure = DependencyGraph::requiresClosure('logistics_shipments');

        $this->assertEmpty($closure);
    }

    public function test_transitive_closure_is_sorted(): void
    {
        $closure = DependencyGraph::requiresClosure('logistics_tracking');

        $sorted = $closure;
        sort($sorted);

        $this->assertSame($sorted, $closure, 'requiresClosure() must return sorted keys');
    }

    // ─── requiredBy() ───────────────────────────────────────

    public function test_required_by_reverse_lookup(): void
    {
        $dependents = DependencyGraph::requiredBy('logistics_shipments');

        $this->assertContains('logistics_tracking', $dependents);
        $this->assertContains('logistics_fleet', $dependents);
        $this->assertContains('logistics_analytics', $dependents);
    }

    public function test_required_by_returns_empty_for_leaf(): void
    {
        $dependents = DependencyGraph::requiredBy('logistics_tracking');

        $this->assertEmpty($dependents);
    }

    public function test_required_by_is_sorted(): void
    {
        $dependents = DependencyGraph::requiredBy('logistics_shipments');

        $sorted = $dependents;
        sort($sorted);

        $this->assertSame($sorted, $dependents, 'requiredBy() must return sorted keys');
    }

    public function test_multi_parent_reverse_lookup(): void
    {
        // logistics_shipments is required by tracking, fleet, analytics
        $dependents = DependencyGraph::requiredBy('logistics_shipments');

        $this->assertGreaterThanOrEqual(3, count($dependents));
    }

    // ─── buildFullGraph() ───────────────────────────────────

    public function test_full_graph_contains_modules_with_requires(): void
    {
        $graph = DependencyGraph::buildFullGraph();

        $this->assertArrayHasKey('logistics_tracking', $graph);
        $this->assertContains('logistics_shipments', $graph['logistics_tracking']);
    }

    public function test_full_graph_omits_modules_without_requires(): void
    {
        $graph = DependencyGraph::buildFullGraph();

        $this->assertArrayNotHasKey('logistics_shipments', $graph);
    }

    public function test_full_graph_is_sorted_by_key(): void
    {
        $graph = DependencyGraph::buildFullGraph();
        $keys = array_keys($graph);

        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'buildFullGraph() keys must be sorted');
    }
}
