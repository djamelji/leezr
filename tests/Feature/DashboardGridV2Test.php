<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Billing\PaymentRegistry;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-149 D4e.3: Dashboard Grid V2 feature tests.
 */
class DashboardGridV2Test extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;
    private User $owner;
    private User $member;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        CompanyPermissionCatalog::sync();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();
        JobdomainRegistry::sync();

        // Platform admin
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin-v2@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Company setup
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Grid Co', 'slug' => 'grid-co', 'jobdomain_key' => 'logistique']);

        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $this->company->jobdomains()->attach($jobdomain->id);

        // Enable core.billing module
        CompanyModule::create([
            'company_id' => $this->company->id,
            'module_key' => 'core.billing',
            'is_enabled_for_company' => true,
        ]);

        // Owner membership
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Member with limited role (no manage-structure)
        $viewerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'viewer',
            'name' => 'Viewer',
            'is_system' => true,
        ]);

        $viewerRole->permissions()->sync(
            CompanyPermission::whereIn('key', ['members.view'])
                ->pluck('id')->toArray(),
        );

        $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $viewerRole->id,
        ]);
    }

    private function actAsPlatform(PlatformUser $user)
    {
        return $this->actingAs($user, 'platform');
    }

    private function actAsCompany(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ── Platform: layout GET returns x/y/w/h ──

    public function test_platform_layout_get_returns_grid_format(): void
    {
        $response = $this->actAsPlatform($this->platformAdmin)
            ->getJson('/api/platform/dashboard/layout');

        $response->assertOk();
        $layout = $response->json('layout');

        $this->assertIsArray($layout);
        $this->assertNotEmpty($layout);

        // Verify x/y/w/h fields
        $first = $layout[0];
        $this->assertArrayHasKey('x', $first);
        $this->assertArrayHasKey('y', $first);
        $this->assertArrayHasKey('w', $first);
        $this->assertArrayHasKey('h', $first);
    }

    // ── Platform: layout PUT validates and rejects overlap ──

    public function test_platform_layout_put_rejects_overlap(): void
    {
        $response = $this->actAsPlatform($this->platformAdmin)
            ->putJson('/api/platform/dashboard/layout', [
                'layout' => [
                    ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => []],
                    ['key' => 'billing.refund_ratio', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('overlap', $response->json('errors.0'));
    }

    // ── Platform: presets endpoint ──

    public function test_platform_presets_endpoint(): void
    {
        $response = $this->actAsPlatform($this->platformAdmin)
            ->getJson('/api/platform/dashboard/layout/presets');

        $response->assertOk();
        $this->assertArrayHasKey('presets', $response->json());
    }

    // ── Company: catalog filtered by audience (ADR-152) ──

    public function test_company_catalog_excludes_platform_audience_widgets(): void
    {
        $response = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/widgets/catalog');

        $response->assertOk();

        $widgets = $response->json('widgets');
        $this->assertEmpty($widgets, 'Company catalog must exclude platform-audience widgets.');
    }

    // ── Company: batch resolve forces company scope ──

    public function test_company_batch_resolve_forces_company_scope(): void
    {
        $response = $this->actAsCompany($this->owner)
            ->postJson('/api/dashboard/widgets/data', [
                'widgets' => [
                    ['key' => 'billing.revenue_trend', 'period' => '30d'],
                ],
            ]);

        $response->assertOk();

        $result = $response->json('results.0');
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('company', $result['data']['scope']);
    }

    // ── Company: layout CRUD ──

    public function test_company_layout_get_returns_empty_by_default(): void
    {
        $response = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/layout');

        $response->assertOk();
        $this->assertEquals([], $response->json('layout'));
    }

    public function test_company_layout_put_saves(): void
    {
        $layout = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'company', 'config' => []],
        ];

        $response = $this->actAsCompany($this->owner)
            ->putJson('/api/dashboard/layout', ['layout' => $layout]);

        $response->assertOk();
        $this->assertCount(1, $response->json('layout'));

        // Verify GET returns saved layout
        $response = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/layout');

        $response->assertOk();
        $this->assertEquals('billing.revenue_trend', $response->json('layout.0.key'));
    }

    // ── Company: manage-structure guard ──

    public function test_company_layout_put_requires_manage_structure(): void
    {
        $layout = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'company', 'config' => []],
        ];

        // Member without manage-structure → 403
        $response = $this->actAsCompany($this->member)
            ->putJson('/api/dashboard/layout', ['layout' => $layout]);

        $response->assertStatus(403);
    }

    // ── Company: suggestions endpoint ──

    public function test_company_suggestions_returns_empty_by_default(): void
    {
        $response = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/suggestions');

        $response->assertOk();
        $this->assertEquals([], $response->json('suggestions'));
    }

    // ── Company: layout PUT rejects overlap ──

    public function test_company_layout_put_rejects_overlap(): void
    {
        $response = $this->actAsCompany($this->owner)
            ->putJson('/api/dashboard/layout', [
                'layout' => [
                    ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'company', 'config' => []],
                    ['key' => 'billing.refund_ratio', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'company', 'config' => []],
                ],
            ]);

        $response->assertStatus(422);
    }

    // ── ADR-150/151: Company dashboard API loads correctly ──

    public function test_company_dashboard_layout_roundtrip(): void
    {
        // 1. Catalog returns empty (billing widgets are platform-audience only)
        $catalog = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/widgets/catalog');

        $catalog->assertOk();
        $this->assertEmpty($catalog->json('widgets'));

        // 2. Layout CRUD still works (seeded/jobdomain layouts may reference any widget)
        $layout = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'company', 'config' => ['period' => '30d']],
            ['key' => 'billing.refund_ratio', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'company', 'config' => []],
        ];

        $this->actAsCompany($this->owner)
            ->putJson('/api/dashboard/layout', ['layout' => $layout])
            ->assertOk();

        // 3. Layout persisted
        $get = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/layout');

        $get->assertOk();
        $this->assertCount(2, $get->json('layout'));
    }

    public function test_company_empty_state_returns_empty_layout(): void
    {
        // New company with no saved layout returns empty array
        $response = $this->actAsCompany($this->owner)
            ->getJson('/api/dashboard/layout');

        $response->assertOk();
        $this->assertIsArray($response->json('layout'));
        $this->assertEmpty($response->json('layout'));
    }
}
