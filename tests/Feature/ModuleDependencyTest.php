<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleService;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module dependency enforcement tests.
 *
 * Validates that:
 *   - A module with unmet requires cannot be activated
 *   - A module that is depended upon by active modules cannot be deactivated
 *   - No automatic cascade: explicit error messages with missing/dependent keys
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
        $this->company = Company::create(['name' => 'Dep Co', 'slug' => 'dep-co']);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        // Assign logistique jobdomain with logistics modules as default
        $jobdomain = Jobdomain::create([
            'key' => 'logistique',
            'label' => 'Logistique',
            'is_active' => true,
            'default_modules' => [
                'logistics_shipments',
                'logistics_tracking',
                'logistics_fleet',
                'logistics_analytics',
            ],
            'allow_custom_fields' => true,
        ]);
        $this->company->jobdomains()->sync([$jobdomain->id]);

        // Enable core modules only — logistics modules stay disabled
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type === 'core') {
                CompanyModule::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }
    }

    // ─── Activation enforcement ─────────────────────────────

    public function test_cannot_activate_module_with_missing_dependency(): void
    {
        // logistics_tracking requires logistics_shipments
        // logistics_shipments is NOT active → activation must fail
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));

        $result = DependencyResolver::canActivate($this->company, 'logistics_tracking');

        $this->assertFalse($result['can_activate']);
        $this->assertContains('logistics_shipments', $result['missing']);
    }

    public function test_service_rejects_activation_with_missing_dependency(): void
    {
        $result = CompanyModuleService::enable($this->company, 'logistics_tracking');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Required modules must be activated first.', $result['data']['message']);
        $this->assertContains('logistics_shipments', $result['data']['missing']);
    }

    public function test_can_activate_module_when_dependency_is_met(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);

        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));

        $result = DependencyResolver::canActivate($this->company, 'logistics_tracking');

        $this->assertTrue($result['can_activate']);
        $this->assertEmpty($result['missing']);
    }

    public function test_service_allows_activation_when_dependency_is_met(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);

        $result = CompanyModuleService::enable($this->company, 'logistics_tracking');

        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['status']);
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
    }

    public function test_module_without_requires_can_always_activate(): void
    {
        $result = DependencyResolver::canActivate($this->company, 'logistics_shipments');

        $this->assertTrue($result['can_activate']);
        $this->assertEmpty($result['missing']);
    }

    // ─── Deactivation enforcement ───────────────────────────

    public function test_cannot_deactivate_module_with_active_dependents(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => true,
        ]);

        $result = DependencyResolver::canDeactivate($this->company, 'logistics_shipments');

        $this->assertFalse($result['can_deactivate']);
        $this->assertContains('logistics_tracking', $result['dependents']);
    }

    public function test_service_rejects_deactivation_with_active_dependents(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => true,
        ]);

        $result = CompanyModuleService::disable($this->company, 'logistics_shipments');

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Other modules depend on this one.', $result['data']['message']);
        $this->assertContains('logistics_tracking', $result['data']['dependents']);
    }

    public function test_can_deactivate_module_when_dependents_are_disabled(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => false,
        ]);

        $result = DependencyResolver::canDeactivate($this->company, 'logistics_shipments');

        $this->assertTrue($result['can_deactivate']);
        $this->assertEmpty($result['dependents']);
    }

    public function test_service_allows_deactivation_when_dependents_are_disabled(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => false,
        ]);

        $result = CompanyModuleService::disable($this->company, 'logistics_shipments');

        $this->assertTrue($result['success']);
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    // ─── Multiple dependents ────────────────────────────────

    public function test_deactivation_lists_all_active_dependents(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_fleet',
            'is_enabled_for_company' => true,
        ]);

        $result = DependencyResolver::canDeactivate($this->company, 'logistics_shipments');

        $this->assertFalse($result['can_deactivate']);
        $this->assertContains('logistics_tracking', $result['dependents']);
        $this->assertContains('logistics_fleet', $result['dependents']);
    }

    // ─── HTTP-level integration ─────────────────────────────

    public function test_http_enable_rejects_missing_dependency(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/modules/logistics_tracking/enable');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Required modules must be activated first.')
            ->assertJsonFragment(['missing' => ['logistics_shipments']]);
    }

    public function test_http_disable_rejects_active_dependents(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/modules/logistics_shipments/disable');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Other modules depend on this one.');

        $dependents = $response->json('dependents');
        $this->assertContains('logistics_tracking', $dependents);
    }

    // ─── No automatic cascade ───────────────────────────────

    public function test_no_automatic_cascade_on_deactivation(): void
    {
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_shipments',
            'is_enabled_for_company' => true,
        ]);
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'is_enabled_for_company' => true,
        ]);

        $result = CompanyModuleService::disable($this->company, 'logistics_shipments');

        $this->assertFalse($result['success']);

        // Tracking must still be active
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_tracking'));
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }
}
