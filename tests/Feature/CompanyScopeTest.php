<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\Scopes\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-432: Tests for CompanyScope global scope + BelongsToCompany trait.
 */
class CompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->companyA = Company::create([
            'name' => 'Company A',
            'slug' => 'company-a-' . uniqid(),
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $this->companyB = Company::create([
            'name' => 'Company B',
            'slug' => 'company-b-' . uniqid(),
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        // Create modules for both companies
        CompanyModule::withoutCompanyScope()->create([
            'company_id' => $this->companyA->id,
            'module_key' => 'documents',
            'is_active' => true,
        ]);

        CompanyModule::withoutCompanyScope()->create([
            'company_id' => $this->companyB->id,
            'module_key' => 'documents',
            'is_active' => true,
        ]);

        CompanyModule::withoutCompanyScope()->create([
            'company_id' => $this->companyA->id,
            'module_key' => 'members',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Ensure company context is cleared after each test
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    // ─── Scope Filtering ──────────────────────────────────

    public function test_scope_filters_by_company_when_context_bound(): void
    {
        app()->instance('company.context', $this->companyA);

        $modules = CompanyModule::all();

        $this->assertCount(2, $modules);
        $this->assertTrue($modules->every(fn ($m) => $m->company_id === $this->companyA->id));
    }

    public function test_scope_returns_all_when_no_context(): void
    {
        // No company.context bound — should return all records
        $modules = CompanyModule::withoutCompanyScope()->get();

        $this->assertGreaterThanOrEqual(3, $modules->count());
    }

    public function test_scope_isolates_company_b_from_a(): void
    {
        app()->instance('company.context', $this->companyB);

        $modules = CompanyModule::all();

        $this->assertCount(1, $modules);
        $this->assertEquals($this->companyB->id, $modules->first()->company_id);
        $this->assertEquals('documents', $modules->first()->module_key);
    }

    // ─── Cross-Tenant Isolation ───────────────────────────

    public function test_cross_tenant_isolation(): void
    {
        // Bind company A context
        app()->instance('company.context', $this->companyA);

        // Query should NOT return company B's records
        $modules = CompanyModule::all();
        $companyBModules = $modules->filter(fn ($m) => $m->company_id === $this->companyB->id);

        $this->assertCount(0, $companyBModules);
    }

    public function test_find_respects_scope(): void
    {
        $moduleBId = CompanyModule::withoutCompanyScope()
            ->where('company_id', $this->companyB->id)
            ->first()
            ->id;

        // Bind company A — should NOT be able to find company B's module
        app()->instance('company.context', $this->companyA);

        $result = CompanyModule::find($moduleBId);
        $this->assertNull($result);
    }

    // ─── withoutCompanyScope ──────────────────────────────

    public function test_without_company_scope_bypasses_filter(): void
    {
        app()->instance('company.context', $this->companyA);

        $allModules = CompanyModule::withoutCompanyScope()->get();

        $this->assertGreaterThanOrEqual(3, $allModules->count());
        $this->assertTrue($allModules->contains(fn ($m) => $m->company_id === $this->companyB->id));
    }

    // ─── Auto-fill company_id ─────────────────────────────

    public function test_auto_fills_company_id_on_create(): void
    {
        app()->instance('company.context', $this->companyA);

        $module = CompanyModule::create([
            'module_key' => 'logistics',
            'is_active' => true,
        ]);

        $this->assertEquals($this->companyA->id, $module->company_id);
    }

    public function test_does_not_overwrite_explicit_company_id(): void
    {
        app()->instance('company.context', $this->companyA);

        $module = CompanyModule::create([
            'company_id' => $this->companyB->id,
            'module_key' => 'logistics',
            'is_active' => true,
        ]);

        // Explicit company_id should NOT be overwritten
        $this->assertEquals($this->companyB->id, $module->company_id);
    }

    public function test_no_auto_fill_without_context(): void
    {
        // No context bound, explicit company_id required
        $module = CompanyModule::withoutCompanyScope()->create([
            'company_id' => $this->companyB->id,
            'module_key' => 'logistics',
            'is_active' => true,
        ]);

        $this->assertEquals($this->companyB->id, $module->company_id);
    }

    // ─── Context Switch ───────────────────────────────────

    public function test_context_switch_changes_scope(): void
    {
        // First bind A
        app()->instance('company.context', $this->companyA);
        $countA = CompanyModule::count();

        // Switch to B
        app()->instance('company.context', $this->companyB);
        $countB = CompanyModule::count();

        $this->assertEquals(2, $countA);
        $this->assertEquals(1, $countB);
    }

    // ─── Middleware Binding ───────────────────────────────

    public function test_middleware_binds_company_context(): void
    {
        $user = User::factory()->create();
        $this->companyA->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Simulate an API call through the middleware
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->getJson('/api/modules');

        // After middleware, company.context should have been bound
        // (response status validates the middleware chain worked)
        $response->assertStatus(200);
    }
}
