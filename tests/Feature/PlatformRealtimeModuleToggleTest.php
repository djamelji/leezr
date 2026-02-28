<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-157: Platform Realtime module toggle tests.
 *
 * Verifies that platform.realtime is a toggleable first-class module.
 * Critical invariant: disabling platform.realtime must NOT gate company SSE stream.
 * BMAD rule: "Aucun module platform.* ne peut gater une route company.*"
 */
class PlatformRealtimeModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $platformAdmin;
    protected Company $company;
    protected User $companyUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'rttest@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Company user for SSE stream test
        $this->company = Company::create([
            'name' => 'RT Test Co',
            'slug' => 'rt-test-co',
            'plan_key' => 'starter',
        ]);

        $this->companyUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->companyUser->id,
            'role' => 'owner',
        ]);
    }

    // ── T1: Disable → realtime governance routes return 403 ──

    public function test_disable_realtime_blocks_governance_routes(): void
    {
        AdminModuleService::disable('platform.realtime');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/realtime/status');

        $response->assertStatus(403);
    }

    // ── T2: Disable → company SSE stream still works (200) ───

    public function test_disable_realtime_company_sse_still_works(): void
    {
        AdminModuleService::disable('platform.realtime');

        // Company SSE stream must NOT be gated by platform module
        $response = $this->actingAs($this->companyUser)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/realtime/stream');

        // SSE returns streamed response (200), not 403
        $this->assertNotEquals(403, $response->getStatusCode(), 'Company SSE stream must NOT be gated by platform.realtime module');
    }

    // ── T3: Disable → realtime nav items absent ──────────────

    public function test_disable_realtime_removes_nav_items(): void
    {
        AdminModuleService::disable('platform.realtime');

        $groups = NavBuilder::forAdmin(null);
        $allKeys = collect($groups)->pluck('items')->flatten(1)->pluck('key')->toArray();

        $this->assertNotContains('realtime', $allKeys);
    }

    // ── T4: Re-enable → all restored ─────────────────────────

    public function test_reenable_realtime_restores_everything(): void
    {
        AdminModuleService::disable('platform.realtime');
        AdminModuleService::enable('platform.realtime');

        // Routes work
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/realtime/status');

        $response->assertStatus(200);

        // Nav items present
        $groups = NavBuilder::forAdmin(null);
        $allKeys = collect($groups)->pluck('items')->flatten(1)->pluck('key')->toArray();

        $this->assertContains('realtime', $allKeys);
    }

    // ── T5: Module manifest declares type platform ───────────

    public function test_realtime_module_is_platform_type(): void
    {
        $manifest = ModuleRegistry::definitions()['platform.realtime'] ?? null;

        $this->assertNotNull($manifest);
        $this->assertEquals('platform', $manifest->type);
        $this->assertEquals('admin', $manifest->scope);
    }

    // ── T6: Granular permissions exist after sync ────────────

    public function test_realtime_permissions_exist(): void
    {
        PlatformPermissionCatalog::sync();

        $expected = [
            'realtime.view',
            'realtime.manage',
            'realtime.metrics.view',
            'realtime.connections.view',
            'realtime.governance',
        ];

        foreach ($expected as $key) {
            $this->assertDatabaseHas('platform_permissions', ['key' => $key]);
        }
    }

    // ── T7: Disable realtime does NOT affect security ────────

    public function test_disable_realtime_does_not_affect_security(): void
    {
        AdminModuleService::disable('platform.realtime');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/security/alerts');

        $response->assertStatus(200);
    }
}
