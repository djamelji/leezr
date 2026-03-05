<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Platform\Jobdomains\UseCases\UpdateJobdomainData;
use App\Modules\Platform\Jobdomains\UseCases\UpdateJobdomainUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-213: Platform jobdomain module dependency resolution tests.
 *
 * Verifies that when platform admin toggles modules in jobdomain defaults:
 *   - ON: transitive requires are auto-added
 *   - OFF: modules with unmet requires are cascade-removed
 */
class JobdomainModuleDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Jobdomain $jobdomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->jobdomain = Jobdomain::create([
            'key' => 'logistique_test',
            'label' => 'Logistique Test',
            'is_active' => true,
            'default_modules' => [],
            'allow_custom_fields' => false,
        ]);
    }

    /**
     * Adding a module that requires another → auto-adds the dependency.
     */
    public function test_adding_module_auto_adds_required_dependencies(): void
    {
        // logistics_tracking requires logistics_shipments
        $manifest = ModuleRegistry::definitions()['logistics_tracking'] ?? null;
        $this->assertNotNull($manifest);
        $this->assertContains('logistics_shipments', $manifest->requires);

        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => ['logistics_tracking'],
        ]));

        $defaults = $result['jobdomain']->default_modules;

        // Both tracking and shipments should be in defaults
        $this->assertContains('logistics_tracking', $defaults);
        $this->assertContains('logistics_shipments', $defaults);
        $this->assertContains('logistics_shipments', $result['auto_added']);
    }

    /**
     * Adding multiple modules with shared dependency → dependency added once.
     */
    public function test_adding_multiple_modules_with_shared_dependency(): void
    {
        // tracking, fleet, analytics all require shipments
        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => ['logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
        ]));

        $defaults = $result['jobdomain']->default_modules;

        $this->assertContains('logistics_shipments', $defaults);
        $this->assertCount(4, $defaults);
        $this->assertContains('logistics_shipments', $result['auto_added']);
    }

    /**
     * Removing a dependency → cascade-removes modules that require it.
     */
    public function test_removing_dependency_cascade_removes_dependents(): void
    {
        // Start with all logistics modules
        $this->jobdomain->update([
            'default_modules' => [
                'logistics_shipments',
                'logistics_tracking',
                'logistics_fleet',
                'logistics_analytics',
            ],
        ]);

        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => [], // Remove shipments → should cascade-remove all
        ]));

        $defaults = $result['jobdomain']->default_modules;

        $this->assertEmpty($defaults);
    }

    /**
     * Removing a module that nothing depends on → no cascade.
     */
    public function test_removing_leaf_module_does_not_cascade(): void
    {
        $this->jobdomain->update([
            'default_modules' => [
                'logistics_shipments',
                'logistics_tracking',
                'logistics_fleet',
            ],
        ]);

        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => ['logistics_shipments', 'logistics_fleet'],
        ]));

        $defaults = $result['jobdomain']->default_modules;

        // Shipments and fleet remain (fleet requires shipments which is still there)
        $this->assertContains('logistics_shipments', $defaults);
        $this->assertContains('logistics_fleet', $defaults);
        $this->assertNotContains('logistics_tracking', $defaults);
        $this->assertEmpty($result['auto_removed']);
    }

    /**
     * Adding a module whose dependency is already present → no auto-add.
     */
    public function test_adding_module_with_existing_dependency_no_duplicate(): void
    {
        $this->jobdomain->update([
            'default_modules' => ['logistics_shipments'],
        ]);

        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => ['logistics_shipments', 'logistics_tracking'],
        ]));

        $defaults = $result['jobdomain']->default_modules;

        $this->assertCount(2, $defaults);
        $this->assertEmpty($result['auto_added']); // shipments was already there
    }

    /**
     * Core module dependencies are not auto-added to defaults (always active).
     */
    public function test_core_dependencies_not_added_to_defaults(): void
    {
        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'default_modules' => ['logistics_shipments'],
        ]));

        $defaults = $result['jobdomain']->default_modules;

        // Core modules should NOT appear in defaults
        $this->assertNotContains('core.members', $defaults);
        $this->assertNotContains('core.settings', $defaults);
    }

    /**
     * Non-module updates don't trigger dependency resolution.
     */
    public function test_non_module_update_returns_empty_auto_changes(): void
    {
        $useCase = new UpdateJobdomainUseCase;
        $result = $useCase->execute(UpdateJobdomainData::fromValidated($this->jobdomain->id, [
            'label' => 'Renamed Domain',
        ]));

        $this->assertEquals('Renamed Domain', $result['jobdomain']->label);
        $this->assertEmpty($result['auto_added']);
        $this->assertEmpty($result['auto_removed']);
    }
}
