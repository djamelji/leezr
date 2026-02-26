<?php

namespace Tests\Unit;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModulePricingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for ModulePricingPolicy invariants (ADR-116).
 */
class ModulePricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    // ─── assertInvariants() ─────────────────────────────────

    public function test_valid_config_passes(): void
    {
        // Default config should pass all invariants
        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    // ─── Rule 1: required module cannot be addon ────────────

    public function test_required_module_cannot_be_addon(): void
    {
        // logistics_shipments is required by tracking, fleet, analytics
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'addon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/logistics_shipments.*required by/');

        ModulePricingPolicy::assertInvariants();
    }

    public function test_required_module_can_be_included(): void
    {
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'included']);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    public function test_required_module_can_be_internal(): void
    {
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'internal']);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    // ─── Rule 2: core cannot be addon ───────────────────────

    public function test_core_module_cannot_be_addon(): void
    {
        PlatformModule::where('key', 'core.members')
            ->update(['pricing_mode' => 'addon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/core module.*addon/i');

        ModulePricingPolicy::assertInvariants();
    }

    public function test_core_module_can_be_included(): void
    {
        PlatformModule::where('key', 'core.members')
            ->update(['pricing_mode' => 'included']);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    // ─── Rule 3: internal must be internal ──────────────────

    public function test_internal_module_must_be_internal_pricing(): void
    {
        // Find an internal module, or skip
        $internalKey = null;
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->type === 'internal') {
                $internalKey = $key;
                break;
            }
        }

        if (!$internalKey) {
            $this->markTestSkipped('No internal modules in registry');
        }

        PlatformModule::where('key', $internalKey)
            ->update(['pricing_mode' => 'addon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/internal module/i');

        ModulePricingPolicy::assertInvariants();
    }

    // ─── Rule 4: transitive requires not addon ──────────────

    public function test_transitive_require_cannot_be_addon(): void
    {
        // logistics_tracking requires logistics_shipments
        // If logistics_shipments is addon → invariant violation
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['pricing_mode' => 'addon']);

        $this->expectException(RuntimeException::class);

        ModulePricingPolicy::assertInvariants();
    }

    // ─── assertProposedPricingMode() ────────────────────────

    public function test_proposed_addon_rejected_for_required_module(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/logistics_shipments.*required by/');

        ModulePricingPolicy::assertProposedPricingMode('logistics_shipments', 'addon');
    }

    public function test_proposed_included_accepted_for_required_module(): void
    {
        ModulePricingPolicy::assertProposedPricingMode('logistics_shipments', 'included');
        $this->assertTrue(true);
    }

    public function test_proposed_addon_accepted_for_leaf_module(): void
    {
        // logistics_tracking is not required by anyone
        ModulePricingPolicy::assertProposedPricingMode('logistics_tracking', 'addon');
        $this->assertTrue(true);
    }

    public function test_proposed_addon_rejected_for_core_module(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/core module/i');

        ModulePricingPolicy::assertProposedPricingMode('core.members', 'addon');
    }

    // ─── Multiple modules graph validated ───────────────────

    public function test_multiple_modules_graph_validated(): void
    {
        // All default pricing should pass
        ModulePricingPolicy::assertInvariants();

        // Set a non-required leaf to addon → should still pass
        PlatformModule::where('key', 'logistics_tracking')
            ->update(['pricing_mode' => 'addon']);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }
}
