<?php

namespace Tests\Unit;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModulePricingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for ModulePricingPolicy invariants (ADR-206).
 *
 * ADR-206 simplified invariants:
 *   Rule 1: core → addon_pricing must be null
 *   Rule 2: internal → addon_pricing must be null
 *
 * Dependency-based invariants (Rules 1/4 from ADR-116) removed.
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

    // ─── Rule 1: core cannot have addon_pricing ─────────────

    public function test_core_module_cannot_have_addon_pricing(): void
    {
        PlatformModule::where('key', 'core.members')
            ->update(['addon_pricing' => json_encode(['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/core module.*addon_pricing/i');

        ModulePricingPolicy::assertInvariants();
    }

    public function test_core_module_without_addon_pricing_passes(): void
    {
        PlatformModule::where('key', 'core.members')
            ->update(['addon_pricing' => null]);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    // ─── Rule 2: internal cannot have addon_pricing ─────────

    public function test_internal_module_cannot_have_addon_pricing(): void
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
            ->update(['addon_pricing' => json_encode(['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 5]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/internal module.*addon_pricing/i');

        ModulePricingPolicy::assertInvariants();
    }

    // ─── assertAddonPricing() ───────────────────────────────

    public function test_addon_pricing_rejected_for_core_module(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/core module/i');

        ModulePricingPolicy::assertAddonPricing('core.members', ['pricing_model' => 'flat']);
    }

    public function test_null_addon_pricing_accepted_for_core_module(): void
    {
        ModulePricingPolicy::assertAddonPricing('core.members', null);
        $this->assertTrue(true);
    }

    public function test_addon_pricing_accepted_for_leaf_module(): void
    {
        // logistics_tracking is not core/internal — addon_pricing is allowed
        ModulePricingPolicy::assertAddonPricing('logistics_tracking', ['pricing_model' => 'flat']);
        $this->assertTrue(true);
    }

    // ─── ADR-206: Required modules CAN have addon_pricing ───

    public function test_required_module_can_have_addon_pricing(): void
    {
        // logistics_shipments is required by tracking, fleet, analytics
        // ADR-206: dependency invariant removed — this is now allowed
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['addon_pricing' => json_encode(['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 50]])]);

        ModulePricingPolicy::assertInvariants();
        $this->assertTrue(true);
    }

    public function test_addon_pricing_on_module_with_dependents_accepted(): void
    {
        // assertAddonPricing should not block based on dependents
        ModulePricingPolicy::assertAddonPricing(
            'logistics_shipments',
            ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 50]],
        );
        $this->assertTrue(true);
    }
}
