<?php

namespace Tests\Feature;

use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-157: Platform Security module toggle tests.
 *
 * Verifies that platform.security is a toggleable first-class module
 * with strict surface separation from platform.realtime.
 */
class PlatformSecurityModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'sectest@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ── T1: Disable → security alert routes return 403 ───────

    public function test_disable_security_blocks_alert_routes(): void
    {
        AdminModuleService::disable('platform.security');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/security/alerts');

        $response->assertStatus(403);
    }

    // ── T2: Disable → security nav items absent ──────────────

    public function test_disable_security_removes_nav_items(): void
    {
        AdminModuleService::disable('platform.security');

        $groups = NavBuilder::forAdmin(null);
        $allKeys = collect($groups)->pluck('items')->flatten(1)->pluck('key')->toArray();

        // ADR-446: Security navItem removed — security accessible via alerts module
        $this->assertNotContains('security', $allKeys);
    }

    // ── T3: Re-enable → all restored ─────────────────────────

    public function test_reenable_security_restores_everything(): void
    {
        AdminModuleService::disable('platform.security');
        AdminModuleService::enable('platform.security');

        // Routes work
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/security/alerts');

        $response->assertStatus(200);

        // ADR-446: Security navItem removed — module still active but no separate nav entry
        $manifest = ModuleRegistry::definitions()['platform.security'] ?? null;
        $this->assertNotNull($manifest);
    }

    // ── T4: Module manifest declares type platform ───────────

    public function test_security_module_is_platform_type(): void
    {
        $manifest = ModuleRegistry::definitions()['platform.security'] ?? null;

        $this->assertNotNull($manifest);
        $this->assertEquals('platform', $manifest->type);
        $this->assertEquals('admin', $manifest->scope);
    }

    // ── T5: Granular permissions exist after sync ────────────

    public function test_security_permissions_exist(): void
    {
        PlatformPermissionCatalog::sync();

        $expected = [
            'security.view',
            'security.manage',
            'security.alerts.view',
            'security.alerts.manage',
            'security.audit.view',
        ];

        foreach ($expected as $key) {
            $this->assertDatabaseHas('platform_permissions', ['key' => $key]);
        }
    }

    // ── T6: Disable security does NOT affect realtime ────────

    public function test_disable_security_does_not_affect_realtime(): void
    {
        AdminModuleService::disable('platform.security');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/realtime/status');

        $response->assertStatus(200);
    }
}
