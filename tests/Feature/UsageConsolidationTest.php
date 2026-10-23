<?php

namespace Tests\Feature;

use App\Core\Navigation\NavBuilder;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-601 — consolidated "Usage & Consumption" page.
 * The Operations hub is reachable by holders of ANY operations capability (view_ai OR
 * view_usage_monitoring included), and each data endpoint keeps its OWN permission so a user only
 * loads the sections they are authorized for (no permission mixing, server-enforced).
 */
class UsageConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function navKeys(array $permissions): array
    {
        $keys = [];
        foreach (NavBuilder::forAdmin($permissions) as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    private function user(array $permissionKeys, string $roleKey): PlatformUser
    {
        $role = PlatformRole::create(['key' => $roleKey, 'name' => $roleKey]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => $roleKey, 'last_name' => 'U', 'email' => $roleKey . '@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    public function test_operations_hub_reachable_by_any_capability(): void
    {
        $this->assertContains('operations', $this->navKeys(['view_ai']), 'view_ai reaches Operations');
        $this->assertContains('operations', $this->navKeys(['view_usage_monitoring']), 'view_usage_monitoring reaches Operations');
        $this->assertContains('operations', $this->navKeys(['view_system_health']), 'view_system_health reaches Operations');
        // A role with no operations capability does NOT see the Operations hub.
        $this->assertNotContains('operations', $this->navKeys(['manage_roles']));
    }

    public function test_usage_endpoints_enforce_their_own_section_permission(): void
    {
        // view_ai only → AI usage allowed, platform usage + per-company FORBIDDEN.
        $ai = $this->user(['view_ai'], 'ai_only');
        $this->actingAs($ai, 'platform')->getJson('/api/platform/ai/usage?period=7d')->assertOk();
        $this->actingAs($ai, 'platform')->getJson('/api/platform/usage/overview?days=30')->assertForbidden();
        $this->actingAs($ai, 'platform')->getJson('/api/platform/usage/company/1?days=30')->assertForbidden();

        // view_usage_monitoring only → platform usage allowed, AI usage FORBIDDEN.
        $um = $this->user(['view_usage_monitoring'], 'usage_only');
        $this->actingAs($um, 'platform')->getJson('/api/platform/usage/overview?days=30')->assertOk();
        $this->actingAs($um, 'platform')->getJson('/api/platform/ai/usage?period=7d')->assertForbidden();
    }

    public function test_super_admin_reaches_all_usage_endpoints(): void
    {
        $root = PlatformUser::create(['first_name' => 'Root', 'last_name' => 'A', 'email' => 'root@t.com', 'password' => 'P@ssw0rd!Strong']);
        $root->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);
        $this->actingAs($root, 'platform')->getJson('/api/platform/usage/overview?days=7')->assertOk();
        $this->actingAs($root, 'platform')->getJson('/api/platform/ai/usage?period=24h')->assertOk();
    }
}
