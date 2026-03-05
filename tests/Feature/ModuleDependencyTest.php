<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module dependency enforcement tests (ADR-115).
 *
 * Validates that:
 *   - Enabling a module cascade-activates its requires with reason='required'
 *   - Disabling a module cleans up orphan dependents (no reasons left)
 *   - DependencyResolver still provides static validation
 *   - activation_reasons is source of truth, company_modules is derived cache
 *
 * Uses real manifests: logistics_tracking, logistics_fleet, logistics_analytics
 * all require logistics_shipments.
 */
class ModuleDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Dep Co', 'slug' => 'dep-co', 'jobdomain_key' => 'logistique']);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        // Assign logistique jobdomain with logistics modules as default
        // ADR-167a: PlatformSeeder now seeds jobdomains, use updateOrCreate
        $jobdomain = Jobdomain::updateOrCreate(
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

        // Enable core modules only — logistics modules stay disabled
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

    // ─── DependencyResolver (static validation) ─────────────

    public function test_resolver_detects_missing_dependency(): void
    {
        // logistics_tracking requires logistics_shipments
        // logistics_shipments is NOT active → resolver reports missing
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));

        $result = DependencyResolver::canActivate($this->company, 'logistics_tracking');

        $this->assertFalse($result['can_activate']);
        $this->assertContains('logistics_shipments', $result['missing']);
    }

    public function test_resolver_reports_active_dependents(): void
    {
        $this->activateModule('logistics_shipments');
        $this->activateModule('logistics_tracking');

        $result = DependencyResolver::canDeactivate($this->company, 'logistics_shipments');

        $this->assertFalse($result['can_deactivate']);
        $this->assertContains('logistics_tracking', $result['dependents']);
    }

    public function test_resolver_lists_all_active_dependents(): void
    {
        // Fleet requires minPlan='pro'
        $this->company->update(['plan_key' => 'pro']);

        $this->activateModule('logistics_shipments');
        $this->activateModule('logistics_tracking');
        $this->activateModule('logistics_fleet');

        $result = DependencyResolver::canDeactivate($this->company, 'logistics_shipments');

        $this->assertFalse($result['can_deactivate']);
        $this->assertContains('logistics_tracking', $result['dependents']);
        $this->assertContains('logistics_fleet', $result['dependents']);
    }

    public function test_module_without_requires_can_always_activate(): void
    {
        $result = DependencyResolver::canActivate($this->company, 'logistics_shipments');

        $this->assertTrue($result['can_activate']);
        $this->assertEmpty($result['missing']);
    }

    // ─── Engine: Cascade activation ─────────────────────────

    public function test_engine_cascade_activates_required_modules(): void
    {
        // Enable logistics_tracking → should auto-activate logistics_shipments
        $result = ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['status']);

        // logistics_shipments should now be active (cascade-activated)
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));

        // Both should be in the activated list
        $this->assertContains('logistics_shipments', $result['data']['activated']);
        $this->assertContains('logistics_tracking', $result['data']['activated']);
    }

    public function test_engine_records_direct_reason_for_requested_module(): void
    {
        $result = ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_tracking');
        $reasonTypes = array_column($reasons, 'reason');

        $this->assertContains('direct', $reasonTypes);
    }

    public function test_engine_records_required_reason_for_cascade_module(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_shipments');

        $requiredReasons = array_filter($reasons, fn ($r) => $r['reason'] === 'required');
        $this->assertNotEmpty($requiredReasons);

        $sources = array_column($requiredReasons, 'source_module_key');
        $this->assertContains('logistics_tracking', $sources);
    }

    public function test_engine_activation_skips_already_active_modules(): void
    {
        // Activate shipments first (directly)
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        // Now activate tracking → shipments already active, should not duplicate
        $result = ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        // logistics_shipments was already active, so should NOT be in activated list
        $this->assertNotContains('logistics_shipments', $result['data']['activated']);
    }

    public function test_engine_activation_with_met_dependency(): void
    {
        // Pre-activate the dependency
        $this->activateModule('logistics_shipments');

        $result = ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
    }

    // ─── Engine: Intelligent disable + orphan cleanup ───────

    public function test_engine_disable_removes_direct_reason(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        ModuleActivationEngine::disable($this->company, 'logistics_shipments');

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_shipments');
        $this->assertEmpty($reasons);

        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    public function test_engine_disable_cleans_up_orphan_dependents(): void
    {
        // Enable tracking (which cascade-activates shipments)
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));

        // Now disable tracking → tracking has no more reasons → deactivated
        // shipments was only required by tracking → orphaned → deactivated
        $result = ModuleActivationEngine::disable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    public function test_engine_disable_keeps_module_with_other_reasons(): void
    {
        // Fleet requires minPlan='pro'
        $this->company->update(['plan_key' => 'pro']);

        // Both tracking and fleet require shipments
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');
        ModuleActivationEngine::enable($this->company, 'logistics_fleet');

        // Disable tracking → shipments still required by fleet → stays active
        $result = ModuleActivationEngine::disable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'),
            'Shipments should stay active because fleet still requires it');
    }

    public function test_engine_disable_direct_keeps_required_reason(): void
    {
        // Enable shipments directly, then enable tracking (which also adds 'required' for shipments)
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        // Disable shipments directly → 'direct' reason removed, but 'required' from tracking remains
        ModuleActivationEngine::disable($this->company, 'logistics_shipments');

        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'),
            'Shipments should stay active because tracking still requires it');

        $reasons = ModuleActivationEngine::reasonsFor($this->company, 'logistics_shipments');
        $reasonTypes = array_column($reasons, 'reason');
        $this->assertNotContains('direct', $reasonTypes);
        $this->assertContains('required', $reasonTypes);
    }

    public function test_engine_core_module_cannot_be_disabled(): void
    {
        $result = ModuleActivationEngine::disable($this->company, 'core.members');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Core modules cannot be disabled.', $result['data']['message']);
    }

    // ─── Cache sync ─────────────────────────────────────────

    public function test_company_modules_cache_is_synced(): void
    {
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        // Check cache reflects activation
        $cm = CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();
        $this->assertTrue($cm->is_enabled_for_company);

        // Check cascade
        $cmShipments = CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->first();
        $this->assertTrue($cmShipments->is_enabled_for_company);

        // Disable
        ModuleActivationEngine::disable($this->company, 'logistics_tracking');

        $cm->refresh();
        $this->assertFalse($cm->is_enabled_for_company);
    }

    // ─── Transitive requires ────────────────────────────────

    public function test_collect_transitive_requires(): void
    {
        // logistics_tracking requires logistics_shipments
        // logistics_shipments requires nothing
        $requires = ModuleActivationEngine::collectTransitiveRequires('logistics_tracking');

        $this->assertContains('logistics_shipments', $requires);
    }

    public function test_collect_transitive_requires_for_module_without_requires(): void
    {
        $requires = ModuleActivationEngine::collectTransitiveRequires('logistics_shipments');

        $this->assertEmpty($requires);
    }

    // ─── HTTP-level integration ─────────────────────────────

    public function test_http_enable_cascade_activates_dependency(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/modules/logistics_tracking/enable');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Module enabled.');

        // Both should be active
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    public function test_http_disable_cleans_up_orphans(): void
    {
        // First enable
        ModuleActivationEngine::enable($this->company, 'logistics_tracking');

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/modules/logistics_tracking/disable');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Module disabled.');

        // Both should be inactive (orphan cleanup)
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function activateModule(string $key): void
    {
        CompanyModuleActivationReason::firstOrCreate([
            'company_id' => $this->company->id,
            'module_key' => $key,
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
            'source_module_key' => null,
        ]);
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => $key],
            ['is_enabled_for_company' => true],
        );
    }
}
