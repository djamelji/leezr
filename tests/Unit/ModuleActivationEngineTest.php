<?php

namespace Tests\Unit;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ModuleActivationEngine (ADR-115).
 *
 * Tests the engine's internal logic:
 * - Transitive requires collection
 * - Activation reason tracking (add/remove/has)
 * - Orphan cleanup
 * - Cache sync
 * - Event dispatch
 */
class ModuleActivationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->company = Company::create(['name' => 'Engine Co', 'slug' => 'engine-co']);

        // Assign logistique jobdomain
        $jobdomain = Jobdomain::firstOrCreate(
            ['key' => 'logistique'],
            [
                'label' => 'Logistique',
                'is_active' => true,
                'default_modules' => [
                    'logistics_shipments',
                    'logistics_tracking',
                    'logistics_fleet',
                    'logistics_analytics',
                ],
                'allow_custom_fields' => true,
            ],
        );
        $this->company->jobdomains()->sync([$jobdomain->id]);

        // Enable core modules with reasons
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type === 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }
    }

    // ─── Transitive requires ────────────────────────────────

    public function test_collect_transitive_requires_single_level(): void
    {
        $requires = ModuleActivationEngine::collectTransitiveRequires('logistics_tracking');

        $this->assertContains('logistics_shipments', $requires);
        $this->assertCount(1, $requires);
    }

    public function test_collect_transitive_requires_no_requires(): void
    {
        $requires = ModuleActivationEngine::collectTransitiveRequires('logistics_shipments');

        $this->assertEmpty($requires);
    }

    public function test_collect_transitive_requires_excludes_self(): void
    {
        $requires = ModuleActivationEngine::collectTransitiveRequires('logistics_tracking');

        $this->assertNotContains('logistics_tracking', $requires);
    }

    // ─── Reason management ──────────────────────────────────

    public function test_has_any_reason_false_when_none(): void
    {
        $this->assertFalse(
            ModuleActivationEngine::hasAnyReason($this->company, 'logistics_shipments'),
        );
    }

    public function test_has_any_reason_true_after_enable(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        $this->assertTrue(
            ModuleActivationEngine::hasAnyReason($this->company, 'logistics_shipments'),
        );
    }

    public function test_reasons_for_returns_all_reasons(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_shipments');

        $this->assertNotEmpty($reasons);
        $this->assertContains('required', array_column($reasons, 'reason'));
        $this->assertContains('logistics_tracking', array_column($reasons, 'source_module_key'));
    }

    public function test_multiple_reasons_tracked_independently(): void
    {
        // Both tracking and fleet require shipments
        $this->company->update(['plan_key' => 'pro']);

        ModuleActivationEngine::enable($this->company, 'logistics_tracking');
        ModuleActivationEngine::enable($this->company, 'logistics_fleet');

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_shipments');
        $sources = array_column(
            array_filter($reasons, fn ($r) => $r['reason'] === 'required'),
            'source_module_key',
        );

        $this->assertContains('logistics_tracking', $sources);
        $this->assertContains('logistics_fleet', $sources);
    }

    // ─── Enable validation ──────────────────────────────────

    public function test_enable_rejects_globally_disabled_module(): void
    {
        \App\Core\Modules\PlatformModule::where('key', 'logistics_shipments')
            ->update(['is_enabled_globally' => false]);

        $result = ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
    }

    public function test_enable_rejects_unentitled_module(): void
    {
        // logistics_fleet requires minPlan='pro', company has no plan (starter)
        $result = ModuleActivationEngine::enable($this->company, 'logistics_fleet');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
        $this->assertStringContainsString('plan', $result['data']['message']);
    }

    public function test_enable_rejects_when_required_module_globally_disabled(): void
    {
        // Disable shipments globally, then try to enable tracking
        \App\Core\Modules\PlatformModule::where('key', 'logistics_shipments')
            ->update(['is_enabled_globally' => false]);

        $result = ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
    }

    // ─── Disable validation ─────────────────────────────────

    public function test_disable_unknown_module_returns_404(): void
    {
        $result = ModuleActivationEngine::disable($this->company, 'nonexistent_module');

        $this->assertFalse($result['success']);
        $this->assertEquals(404, $result['status']);
    }

    public function test_disable_core_module_returns_422(): void
    {
        $result = ModuleActivationEngine::disable($this->company, 'core.members');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
    }

    // ─── Orphan cleanup ─────────────────────────────────────

    public function test_orphan_cleanup_cascades_through_chain(): void
    {
        // Enable tracking (which requires shipments)
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));

        // Disable tracking → tracking orphaned → its required reason for shipments removed
        // → shipments has no reasons → orphaned → deactivated
        ModuleActivationEngine::disable($this->company, 'logistics_tracking');

        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    public function test_orphan_cleanup_preserves_direct_reason(): void
    {
        // Enable shipments directly, then enable tracking
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        // Disable tracking → shipments still has 'direct' reason → stays active
        ModuleActivationEngine::disable($this->company, 'logistics_tracking');

        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    // ─── Cache sync ─────────────────────────────────────────

    public function test_cache_reflects_activation(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        $cm = CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->first();

        $this->assertNotNull($cm);
        $this->assertTrue($cm->is_enabled_for_company);
    }

    public function test_cache_reflects_deactivation(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        ModuleActivationEngine::disable($this->company, 'logistics_shipments');

        $cm = CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->first();

        $this->assertNotNull($cm);
        $this->assertFalse($cm->is_enabled_for_company);
    }

    // ─── Idempotency ────────────────────────────────────────

    public function test_enable_is_idempotent(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        $result = ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        $this->assertTrue($result['success']);

        // Should still have exactly one 'direct' reason
        $reasons = CompanyModuleActivationReason::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->where('reason', 'direct')
            ->count();

        $this->assertEquals(1, $reasons);
    }

    public function test_disable_is_idempotent(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        ModuleActivationEngine::disable($this->company, 'logistics_shipments');
        $result = ModuleActivationEngine::disable($this->company, 'logistics_shipments');

        $this->assertTrue($result['success']);
    }
}
