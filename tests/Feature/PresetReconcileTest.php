<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\CompanyPresetSnapshot;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Jobdomains\PresetReconciler;
use App\Core\Jobdomains\ReconciliationReport;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-375: Preset reconciliation engine tests.
 *
 * Verifies:
 * - Dry-run detects permission drift
 * - Apply fixes drift and creates snapshot
 * - Custom roles (is_system=false) are skipped
 * - Missing roles are created on apply
 * - Artisan command outputs correctly
 * - Registration snapshot is captured
 */
class PresetReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();
        JobdomainRegistry::sync();

        $this->company = Company::create([
            'name' => 'Reconcile Co',
            'slug' => 'reconcile-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $this->company->jobdomains()->attach($jobdomain->id);

        // Enable all company modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );

            if ($def->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
            }
        }

        // Seed default roles (simulating what JobdomainGate::assignToCompany does)
        $this->seedRolesFromPreset();
    }

    private function seedRolesFromPreset(): void
    {
        $definition = JobdomainRegistry::get('logistique');
        $presets = \App\Core\Jobdomains\JobdomainPresetResolver::resolve('logistique', $this->company->market_key);
        $archetypes = $definition['archetypes'] ?? [];

        foreach ($presets->roles as $roleKey => $roleDef) {
            $archetype = $roleDef['archetype'] ?? null;
            $requiredTags = null;
            if ($archetype && isset($archetypes[$archetype])) {
                $requiredTags = $archetypes[$archetype]['default_tags'] ?? [];
            }

            $role = CompanyRole::updateOrCreate(
                ['company_id' => $this->company->id, 'key' => $roleKey],
                [
                    'name' => $roleDef['name'],
                    'is_system' => true,
                    'is_administrative' => $roleDef['is_administrative'] ?? false,
                    'archetype' => $archetype,
                    'required_tags' => $requiredTags,
                ],
            );

            $bundlePermKeys = ModuleRegistry::resolveBundles($roleDef['bundles'] ?? []);
            $directPermKeys = $roleDef['permissions'] ?? [];
            $allPermKeys = array_unique(array_merge($bundlePermKeys, $directPermKeys));

            $permissionIds = CompanyPermission::whereIn('key', $allPermKeys)->pluck('id')->toArray();
            $role->syncPermissionsSafe($permissionIds);
        }
    }

    // ═══════════════════════════════════════════════════════
    // DRY-RUN — no drift when preset matches
    // ═══════════════════════════════════════════════════════

    public function test_dry_run_detects_no_drift_when_up_to_date(): void
    {
        $report = PresetReconciler::reconcile($this->company, false);

        $this->assertFalse($report->hasDrift());
        $this->assertGreaterThan(0, count($report->upToDate));
        $this->assertCount(0, $report->drifted);
        $this->assertCount(0, $report->skipped);
    }

    // ═══════════════════════════════════════════════════════
    // DRY-RUN — detects permission drift
    // ═══════════════════════════════════════════════════════

    public function test_dry_run_detects_missing_permission(): void
    {
        // Remove a permission from the manager role
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        $report = PresetReconciler::reconcile($this->company, false);

        $this->assertTrue($report->hasDrift());

        $managerDrift = collect($report->drifted)->firstWhere('role_key', 'manager');
        $this->assertNotNull($managerDrift);
        $this->assertContains('billing.manage', $managerDrift['missing']);
        $this->assertFalse($managerDrift['applied']);
    }

    public function test_dry_run_detects_extra_permission(): void
    {
        // Add an extra non-admin permission that driver does NOT have in preset
        $driverRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'driver')->first();
        $rolesViewPerm = CompanyPermission::where('key', 'roles.view')->first();
        $driverRole->permissions()->attach($rolesViewPerm->id);

        $report = PresetReconciler::reconcile($this->company, false);

        $this->assertTrue($report->hasDrift());

        $driverDrift = collect($report->drifted)->firstWhere('role_key', 'driver');
        $this->assertNotNull($driverDrift);
        $this->assertContains('roles.view', $driverDrift['extra']);
    }

    // ═══════════════════════════════════════════════════════
    // APPLY — fixes drift and creates snapshot
    // ═══════════════════════════════════════════════════════

    public function test_apply_fixes_drift_and_creates_snapshot(): void
    {
        // Remove a permission to create drift
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        // Verify drift exists
        $dryRun = PresetReconciler::reconcile($this->company, false);
        $this->assertTrue($dryRun->hasDrift());

        // Apply
        $report = PresetReconciler::reconcile($this->company, true);
        $this->assertTrue($report->hasDrift()); // Still reports drift (but applied=true)

        $managerDrift = collect($report->drifted)->firstWhere('role_key', 'manager');
        $this->assertTrue($managerDrift['applied']);

        // Verify snapshot was created
        $snapshot = CompanyPresetSnapshot::where('company_id', $this->company->id)
            ->where('trigger', 'reconcile_apply')
            ->latest()
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals('logistique', $snapshot->jobdomain_key);
        $this->assertIsArray($snapshot->roles_snapshot);

        // Verify permission was restored
        $managerRole->load('permissions');
        $this->assertTrue($managerRole->permissions->contains('key', 'billing.manage'));
    }

    public function test_apply_twice_results_in_no_drift(): void
    {
        // Remove permission, apply
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        PresetReconciler::reconcile($this->company, true);

        // Second reconcile should detect no drift
        $report = PresetReconciler::reconcile($this->company, false);
        $this->assertFalse($report->hasDrift());
    }

    // ═══════════════════════════════════════════════════════
    // CUSTOM ROLES — skipped
    // ═══════════════════════════════════════════════════════

    public function test_custom_roles_are_skipped(): void
    {
        // Create a custom role with same key as a preset role but is_system=false
        $customRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'custom_role',
            'name' => 'Custom Role',
            'is_system' => false,
            'is_administrative' => false,
        ]);

        $report = PresetReconciler::reconcile($this->company, false);

        // Custom role should not appear in drifted (it's not in preset)
        $this->assertEmpty(collect($report->drifted)->where('role_key', 'custom_role'));
        $this->assertEmpty(collect($report->skipped)->where('role_key', 'custom_role'));
    }

    public function test_system_role_with_matching_key_but_not_system_is_skipped(): void
    {
        // Change manager to is_system=false (company customized it)
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $managerRole->update(['is_system' => false]);

        // Remove a permission to create potential drift
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        $report = PresetReconciler::reconcile($this->company, true);

        // Should be skipped, not drifted
        $skipped = collect($report->skipped)->firstWhere('role_key', 'manager');
        $this->assertNotNull($skipped);
        $this->assertStringContainsString('Custom role', $skipped['reason']);

        // Permission should NOT be restored
        $managerRole->load('permissions');
        $this->assertFalse($managerRole->permissions->contains('key', 'billing.manage'));
    }

    // ═══════════════════════════════════════════════════════
    // MISSING ROLES — created on apply
    // ═══════════════════════════════════════════════════════

    public function test_missing_role_is_created_on_apply(): void
    {
        // Delete the ops_manager role
        CompanyRole::where('company_id', $this->company->id)->where('key', 'ops_manager')->delete();

        $report = PresetReconciler::reconcile($this->company, true);

        $opsManagerDrift = collect($report->drifted)->firstWhere('role_key', 'ops_manager');
        $this->assertNotNull($opsManagerDrift);
        $this->assertTrue($opsManagerDrift['applied']);

        // Verify role was recreated
        $recreatedRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'ops_manager')->first();
        $this->assertNotNull($recreatedRole);
        $this->assertTrue($recreatedRole->is_system);
        $this->assertTrue($recreatedRole->is_administrative);
        $this->assertEquals('management', $recreatedRole->archetype);
        $this->assertGreaterThan(0, $recreatedRole->permissions()->count());
    }

    // ═══════════════════════════════════════════════════════
    // ORPHAN ROLES — warning for system roles not in preset
    // ═══════════════════════════════════════════════════════

    public function test_orphan_system_role_generates_warning(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'orphan_system',
            'name' => 'Orphan',
            'is_system' => true,
            'is_administrative' => false,
        ]);

        $report = PresetReconciler::reconcile($this->company, false);

        $orphanWarning = collect($report->warnings)->first(fn ($w) => str_contains($w, 'orphan_system'));
        $this->assertNotNull($orphanWarning);
    }

    // ═══════════════════════════════════════════════════════
    // BY JOBDOMAIN — batch reconciliation
    // ═══════════════════════════════════════════════════════

    public function test_reconcile_by_jobdomain(): void
    {
        $report = PresetReconciler::reconcileByJobdomain('logistique', false);

        $this->assertFalse($report->hasDrift());
        $this->assertGreaterThan(0, count($report->upToDate));
    }

    // ═══════════════════════════════════════════════════════
    // REPORT — value object
    // ═══════════════════════════════════════════════════════

    public function test_report_to_array_structure(): void
    {
        $report = new ReconciliationReport;
        $report->addUpToDate(1, 'manager', 10);
        $report->addDrifted(1, 'driver', 11, ['billing.manage'], ['extra.perm'], false);
        $report->addSkipped(1, 'custom', 12, 'Custom role');
        $report->addWarning('Test warning');

        $array = $report->toArray();

        $this->assertEquals(['up_to_date' => 1, 'drifted' => 1, 'skipped' => 1, 'warnings' => 1], $array['summary']);
        $this->assertCount(1, $array['up_to_date']);
        $this->assertCount(1, $array['drifted']);
        $this->assertCount(1, $array['skipped']);
        $this->assertCount(1, $array['warnings']);
    }

    // ═══════════════════════════════════════════════════════
    // ARTISAN COMMAND
    // ═══════════════════════════════════════════════════════

    public function test_artisan_dry_run_returns_success_when_no_drift(): void
    {
        $this->artisan('permissions:reconcile', ['--company' => $this->company->id])
            ->assertExitCode(0);
    }

    public function test_artisan_dry_run_returns_failure_when_drift(): void
    {
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        $this->artisan('permissions:reconcile', ['--company' => $this->company->id])
            ->assertExitCode(1);
    }

    public function test_artisan_apply_returns_success(): void
    {
        $managerRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'manager')->first();
        $billingPerm = CompanyPermission::where('key', 'billing.manage')->first();
        $managerRole->permissions()->detach($billingPerm->id);

        $this->artisan('permissions:reconcile', ['--company' => $this->company->id, '--apply' => true])
            ->assertExitCode(0);
    }

    public function test_artisan_json_output(): void
    {
        $this->artisan('permissions:reconcile', ['--company' => $this->company->id, '--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"up_to_date"');
    }

    // ═══════════════════════════════════════════════════════
    // SNAPSHOT — registration snapshot via JobdomainGate
    // ═══════════════════════════════════════════════════════

    public function test_registration_creates_snapshot(): void
    {
        $newCompany = Company::create([
            'name' => 'Snapshot Co',
            'slug' => 'snapshot-co',
            'plan_key' => 'starter',
        ]);

        $owner = User::factory()->create();
        $newCompany->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        \App\Core\Jobdomains\JobdomainGate::assignToCompany($newCompany, 'logistique');

        $snapshot = CompanyPresetSnapshot::where('company_id', $newCompany->id)
            ->where('trigger', 'registration')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals('logistique', $snapshot->jobdomain_key);
        $this->assertNotEmpty($snapshot->roles_snapshot);

        // Verify snapshot contains the 4 preset roles
        $roleKeys = collect($snapshot->roles_snapshot)->pluck('role_key')->all();
        $this->assertContains('manager', $roleKeys);
        $this->assertContains('dispatcher', $roleKeys);
        $this->assertContains('driver', $roleKeys);
        $this->assertContains('ops_manager', $roleKeys);
    }

    // ═══════════════════════════════════════════════════════
    // IS_ADMINISTRATIVE DRIFT — detected
    // ═══════════════════════════════════════════════════════

    public function test_is_administrative_drift_detected(): void
    {
        // Flip dispatcher's is_administrative
        $dispatcherRole = CompanyRole::where('company_id', $this->company->id)->where('key', 'dispatcher')->first();
        $dispatcherRole->update(['is_administrative' => false]);

        $report = PresetReconciler::reconcile($this->company, false);

        // Should detect drift even though permissions match
        $dispatcherDrift = collect($report->drifted)->firstWhere('role_key', 'dispatcher');
        $this->assertNotNull($dispatcherDrift, 'is_administrative flip should be detected as drift.');
    }
}
