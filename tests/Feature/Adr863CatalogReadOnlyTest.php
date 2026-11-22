<?php

namespace Tests\Feature;

use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-863 — JobDomains, Modules and the Document catalog had a manage right only : a reader (DEV recipe account) had
 * to be given write rights. view_jobdomains / view_modules / view_document_catalog open the GET endpoints only ; every
 * write still requires the manage right, and the historical admin role is never offered the new rights.
 */
class Adr863CatalogReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function userWith(array $permissions): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'R', 'last_name' => 'O', 'email' => 'ro-'.uniqid().'@leezr-test.invalid', 'password' => 'P@ssw0rd!Strong-863']);
        $role = PlatformRole::create(['key' => 'recette_'.uniqid(), 'name' => 'Recette lecture']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $u->roles()->attach($role->id);

        return $u;
    }

    private const READS = ['/api/platform/modules', '/api/platform/jobdomains', '/api/platform/documents', '/api/platform/contract-templates', '/api/platform/organization-presets', '/api/platform/presets'];

    public function test_the_three_read_rights_exist_and_are_not_offered_to_the_admin_role(): void
    {
        foreach (PlatformPermissionCatalog::CATALOG_READ_ONLY as $key) {
            $this->assertTrue(PlatformPermission::where('key', $key)->exists(), "$key is declared by its module");
        }
        $admin = PlatformRole::where('key', 'admin')->first();
        $this->assertSame([], array_values(array_intersect(PlatformPermissionCatalog::CATALOG_READ_ONLY, $admin->permissions()->pluck('platform_permissions.key')->all())));
        $this->assertTrue($admin->permissions()->where('platform_permissions.key', 'manage_modules')->exists(), 'admin keeps its manage rights');

        // a second sync (deployment) does not offer them either
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->assertSame(0, $admin->permissions()->whereIn('platform_permissions.key', PlatformPermissionCatalog::CATALOG_READ_ONLY)->count());
    }

    public function test_a_reader_reads_the_catalog_pages_but_every_write_is_refused(): void
    {
        $reader = $this->userWith(['view_dashboard', ...PlatformPermissionCatalog::CATALOG_READ_ONLY]);
        $api = $this->actingAs($reader, 'platform');

        foreach (self::READS as $url) {
            $api->getJson($url)->assertOk();
        }
        $key = $api->getJson('/api/platform/modules')->json('modules.0.key') ?? 'logistics_shipments';
        $api->getJson("/api/platform/modules/{$key}")->assertOk();

        $api->putJson("/api/platform/modules/{$key}/toggle")->assertForbidden();
        $api->putJson("/api/platform/modules/{$key}/config", ['display_name' => 'x'])->assertForbidden();
        $api->postJson('/api/platform/modules/sync')->assertForbidden();
        $api->postJson('/api/platform/jobdomains', ['key' => 'ro', 'label' => 'RO'])->assertForbidden();
        $api->postJson('/api/platform/documents/sync')->assertForbidden();
        $api->postJson('/api/platform/documents', ['code' => 'ro', 'label' => 'RO'])->assertForbidden();
        $api->postJson('/api/platform/contract-templates', ['name' => 'RO'])->assertForbidden();
        $api->postJson('/api/platform/organization-presets', ['key' => 'ro', 'label' => 'RO'])->assertForbidden();
        $api->postJson('/api/platform/presets', ['kind' => 'rbac'])->assertForbidden();
        $api->postJson('/api/platform/contract-templates/preview-pdf', [])->assertForbidden(); // POST stays a write right
    }

    public function test_without_any_catalog_right_the_reads_stay_refused_and_manage_is_unchanged(): void
    {
        $nobody = $this->userWith(['view_dashboard']);
        foreach (self::READS as $url) {
            $this->actingAs($nobody, 'platform')->getJson($url)->assertForbidden();
        }

        // one read right opens its own family only
        $modulesOnly = $this->userWith(['view_modules']);
        $this->actingAs($modulesOnly, 'platform')->getJson('/api/platform/modules')->assertOk();
        $this->actingAs($modulesOnly, 'platform')->getJson('/api/platform/jobdomains')->assertForbidden();
        $this->actingAs($modulesOnly, 'platform')->getJson('/api/platform/documents')->assertForbidden();

        $manager = $this->userWith(['manage_modules']);
        $this->actingAs($manager, 'platform')->postJson('/api/platform/modules/sync')->assertOk();
    }
}
