<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Company RBAC — middleware company.permission tests.
 *
 * Couvre :
 *   - Owner bypass (toutes permissions)
 *   - Admin role (toutes permissions)
 *   - Viewer role (lecture seule → refusé sur manage)
 *   - Member sans rôle (refusé sur tout ce qui est protégé)
 *   - Module inactif → 403 même avec permission
 *   - Enrichissement /api/my-companies
 */
class CompanyPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $viewer;
    private User $noRole;
    private Company $company;
    private $ownerMembership;
    private $adminMembership;
    private $viewerMembership;
    private $noRoleMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync(); // Sync PlatformModule records
        FieldDefinitionCatalog::sync();
        CompanyPermissionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->noRole = User::factory()->create();

        $this->company = Company::create(['name' => 'RBAC Co', 'slug' => 'rbac-co']);

        // Activer tous les modules
        foreach (ModuleRegistry::definitions() as $key => $def) {
            CompanyModule::create([
                'company_id' => $this->company->id,
                'module_key' => $key,
                'is_enabled_for_company' => true,
            ]);
        }

        // Créer les rôles
        $adminRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'admin',
            'name' => 'Administrator',
            'is_system' => true,
            'is_administrative' => true,
        ]);

        $adminRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        $viewerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'viewer',
            'name' => 'Viewer',
            'is_system' => true,
        ]);

        $viewerRole->permissions()->sync(
            CompanyPermission::whereIn('key', ['members.view', 'settings.view', 'shipments.view'])
                ->pluck('id')->toArray(),
        );

        // Créer les memberships
        $this->ownerMembership = $this->company->memberships()->create([
            'user_id' => $this->owner->id, 'role' => 'owner',
        ]);

        $this->adminMembership = $this->company->memberships()->create([
            'user_id' => $this->admin->id, 'role' => 'admin',
            'company_role_id' => $adminRole->id,
        ]);

        $this->viewerMembership = $this->company->memberships()->create([
            'user_id' => $this->viewer->id, 'role' => 'user',
            'company_role_id' => $viewerRole->id,
        ]);

        $this->noRoleMembership = $this->company->memberships()->create([
            'user_id' => $this->noRole->id, 'role' => 'user',
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // Batch 1 : Shipments
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_create_shipment(): void
    {
        $response = $this->actAs($this->owner)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
            ]);

        $response->assertStatus(201);
    }

    public function test_admin_can_create_shipment(): void
    {
        $response = $this->actAs($this->admin)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
            ]);

        $response->assertStatus(201);
    }

    public function test_viewer_cannot_create_shipment(): void
    {
        $response = $this->actAs($this->viewer)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
            ]);

        $response->assertStatus(403);
    }

    public function test_viewer_can_list_shipments(): void
    {
        $response = $this->actAs($this->viewer)
            ->getJson('/api/shipments');

        $response->assertOk();
    }

    public function test_no_role_cannot_create_shipment(): void
    {
        $response = $this->actAs($this->noRole)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_change_shipment_status(): void
    {
        $shipment = Shipment::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'reference' => 'SHP-TEST-001',
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => '1 Rue A',
            'destination_address' => '2 Rue B',
        ]);

        $response = $this->actAs($this->admin)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => Shipment::STATUS_PLANNED,
            ]);

        $response->assertOk();
    }

    public function test_viewer_cannot_change_shipment_status(): void
    {
        $shipment = Shipment::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'reference' => 'SHP-TEST-002',
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => '1 Rue A',
            'destination_address' => '2 Rue B',
        ]);

        $response = $this->actAs($this->viewer)
            ->putJson("/api/shipments/{$shipment->id}/status", [
                'status' => Shipment::STATUS_PLANNED,
            ]);

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Batch 1 bis : Module inactive → 403 même avec permission
    // ═══════════════════════════════════════════════════════

    public function test_module_inactive_denies_even_with_permission(): void
    {
        // Désactiver le module logistics_shipments
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        // Admin a la permission shipments.create mais le module est off
        $response = $this->actAs($this->admin)
            ->postJson('/api/shipments', [
                'origin_address' => '1 Rue A',
                'destination_address' => '2 Rue B',
            ]);

        $response->assertStatus(403);
    }

    public function test_module_inactive_denies_read_too(): void
    {
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $response = $this->actAs($this->owner)
            ->getJson('/api/shipments');

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Batch 2 : Members
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_invite_member(): void
    {
        $response = $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'new@test.dev',
            ]);

        $response->assertStatus(201);
    }

    public function test_admin_can_invite_member(): void
    {
        $response = $this->actAs($this->admin)
            ->postJson('/api/company/members', [
                'email' => 'new2@test.dev',
            ]);

        $response->assertStatus(201);
    }

    public function test_viewer_cannot_invite_member(): void
    {
        $response = $this->actAs($this->viewer)
            ->postJson('/api/company/members', [
                'email' => 'denied@test.dev',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_update_member(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->viewerMembership->id}", [
                'first_name' => 'Updated',
            ]);

        $response->assertOk();
    }

    public function test_viewer_cannot_update_member(): void
    {
        $response = $this->actAs($this->viewer)
            ->putJson("/api/company/members/{$this->noRoleMembership->id}", [
                'first_name' => 'Hacked',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_member(): void
    {
        $response = $this->actAs($this->admin)
            ->deleteJson("/api/company/members/{$this->noRoleMembership->id}");

        $response->assertOk();
    }

    public function test_viewer_cannot_delete_member(): void
    {
        $response = $this->actAs($this->viewer)
            ->deleteJson("/api/company/members/{$this->noRoleMembership->id}");

        $response->assertStatus(403);
    }

    public function test_no_role_cannot_manage_members(): void
    {
        $response = $this->actAs($this->noRole)
            ->postJson('/api/company/members', [
                'email' => 'norole@test.dev',
            ]);

        $response->assertStatus(403);
    }

    // ─── Member credentials ─────────────────────────────────

    public function test_admin_can_reset_member_password(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $response = $this->actAs($this->admin)
            ->postJson("/api/company/members/{$this->viewerMembership->id}/reset-password");

        $response->assertOk();
    }

    public function test_viewer_cannot_reset_member_password(): void
    {
        $response = $this->actAs($this->viewer)
            ->postJson("/api/company/members/{$this->noRoleMembership->id}/reset-password");

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Batch 3 : Settings / Modules / Fields
    // ═══════════════════════════════════════════════════════

    public function test_admin_can_update_company(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson('/api/company', ['name' => 'New Name']);

        $response->assertOk();
    }

    public function test_viewer_cannot_update_company(): void
    {
        $response = $this->actAs($this->viewer)
            ->putJson('/api/company', ['name' => 'Hacked']);

        $response->assertStatus(403);
    }

    public function test_viewer_can_read_company(): void
    {
        $response = $this->actAs($this->viewer)
            ->getJson('/api/company');

        $response->assertOk();
    }

    public function test_admin_can_enable_module(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson('/api/modules/logistics_shipments/enable');

        $response->assertOk();
    }

    public function test_viewer_cannot_enable_module(): void
    {
        $response = $this->actAs($this->viewer)
            ->putJson('/api/modules/logistics_shipments/enable');

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // API enrichi : /api/my-companies
    // ═══════════════════════════════════════════════════════

    public function test_my_companies_returns_company_role_for_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/my-companies');

        $response->assertOk();

        $company = collect($response->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertNotNull($company['company_role']);
        $this->assertEquals('admin', $company['company_role']['key']);
        $this->assertContains('members.manage', $company['company_role']['permissions']);
        $this->assertContains('shipments.create', $company['company_role']['permissions']);
    }

    public function test_my_companies_returns_null_role_for_owner(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/my-companies');

        $response->assertOk();

        $company = collect($response->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertEquals('owner', $company['role']);
        $this->assertNull($company['company_role']);
    }

    public function test_my_companies_returns_viewer_permissions(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/my-companies');

        $response->assertOk();

        $company = collect($response->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertEquals('viewer', $company['company_role']['key']);
        $this->assertContains('shipments.view', $company['company_role']['permissions']);
        $this->assertNotContains('shipments.create', $company['company_role']['permissions']);
    }

    public function test_my_companies_returns_null_role_for_no_role_member(): void
    {
        $response = $this->actingAs($this->noRole)
            ->getJson('/api/my-companies');

        $response->assertOk();

        $company = collect($response->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertNull($company['company_role']);
    }

    // ═══════════════════════════════════════════════════════
    // R2.5 Hardening : séparation admin / opérationnel
    // ═══════════════════════════════════════════════════════

    public function test_operational_role_cannot_receive_admin_permission(): void
    {
        $operationalRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $adminPermIds = CompanyPermission::where('is_admin', true)
            ->pluck('id')->toArray();

        $this->expectException(ValidationException::class);

        $operationalRole->syncPermissionsSafe($adminPermIds);
    }

    public function test_operational_role_can_receive_operational_permissions(): void
    {
        $operationalRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $opPermIds = CompanyPermission::where('is_admin', false)
            ->pluck('id')->toArray();

        // Ne doit pas throw
        $operationalRole->syncPermissionsSafe($opPermIds);

        $this->assertCount(count($opPermIds), $operationalRole->permissions);
    }

    public function test_administrative_role_can_receive_admin_permissions(): void
    {
        $adminRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $allPermIds = CompanyPermission::pluck('id')->toArray();

        // Ne doit pas throw
        $adminRole->syncPermissionsSafe($allPermIds);

        $this->assertCount(count($allPermIds), $adminRole->permissions);
    }

    public function test_mixed_permissions_rejected_for_operational_role(): void
    {
        $operationalRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'hybrid',
            'name' => 'Hybrid',
            'is_administrative' => false,
        ]);

        // Mix : 1 operational + 1 admin
        $viewPerm = CompanyPermission::where('key', 'shipments.view')->first();
        $adminPerm = CompanyPermission::where('key', 'settings.manage')->first();

        $this->expectException(ValidationException::class);

        $operationalRole->syncPermissionsSafe([$viewPerm->id, $adminPerm->id]);
    }

    public function test_empty_permissions_accepted_for_operational_role(): void
    {
        $operationalRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'empty',
            'name' => 'Empty',
            'is_administrative' => false,
        ]);

        $operationalRole->syncPermissionsSafe([]);

        $this->assertCount(0, $operationalRole->permissions);
    }

    public function test_admin_permissions_are_correctly_flagged(): void
    {
        $adminPerms = CompanyPermission::where('is_admin', true)->pluck('key')->sort()->values()->toArray();
        $opPerms = CompanyPermission::where('is_admin', false)->pluck('key')->sort()->values()->toArray();

        // 5 admin permissions
        $this->assertEquals([
            'members.credentials',
            'members.manage',
            'settings.manage',
            'shipments.delete',
            'shipments.manage_fields',
        ], $adminPerms);

        // 6 operational permissions
        $this->assertEquals([
            'members.invite',
            'members.view',
            'settings.view',
            'shipments.create',
            'shipments.manage_status',
            'shipments.view',
        ], $opPerms);
    }

    // ═══════════════════════════════════════════════════════
    // members.invite — operational permission split
    // ═══════════════════════════════════════════════════════

    public function test_user_with_invite_permission_can_post_member(): void
    {
        $inviterRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'inviter',
            'name' => 'Inviter',
        ]);

        $invitePerm = CompanyPermission::where('key', 'members.invite')->first();
        $viewPerm = CompanyPermission::where('key', 'members.view')->first();
        $inviterRole->permissions()->sync([$invitePerm->id, $viewPerm->id]);

        $inviterUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $inviterUser->id,
            'role' => 'user',
            'company_role_id' => $inviterRole->id,
        ]);

        $response = $this->actAs($inviterUser)
            ->postJson('/api/company/members', ['email' => 'invited-by-inviter@test.dev']);

        $response->assertStatus(201);
    }

    public function test_user_without_invite_permission_cannot_post_member(): void
    {
        // viewer role has members.view but NOT members.invite
        $response = $this->actAs($this->viewer)
            ->postJson('/api/company/members', ['email' => 'should-fail@test.dev']);

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Company Roles CRUD (manage-structure — owner + administrative)
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_list_roles(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/roles');

        $response->assertOk()->assertJsonStructure(['roles']);
    }

    public function test_administrative_can_list_roles(): void
    {
        $response = $this->actAs($this->admin)->getJson('/api/company/roles');

        $response->assertOk()->assertJsonStructure(['roles']);
    }

    public function test_operational_cannot_list_roles(): void
    {
        $response = $this->actAs($this->viewer)->getJson('/api/company/roles');

        $response->assertStatus(403);
    }

    public function test_owner_can_create_role(): void
    {
        $response = $this->actAs($this->owner)
            ->postJson('/api/company/roles', [
                'name' => 'Custom Role',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['key' => 'custom_role']);
    }

    public function test_owner_can_get_permission_catalog(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/permissions');

        $response->assertOk()->assertJsonStructure([
            'permissions' => [['id', 'key', 'label', 'module_key', 'is_admin', 'module_name', 'module_description', 'hint', 'module_active']],
            'modules' => [['module_key', 'module_name', 'module_active', 'is_core', 'capabilities']],
        ]);
    }

    public function test_owner_can_delete_non_system_role(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'deletable',
            'name' => 'Deletable',
        ]);

        $response = $this->actAs($this->owner)
            ->deleteJson("/api/company/roles/{$role->id}");

        $response->assertOk();
    }

    public function test_owner_cannot_delete_system_role(): void
    {
        $systemRole = CompanyRole::where('company_id', $this->company->id)
            ->where('is_system', true)->first();

        $response = $this->actAs($this->owner)
            ->deleteJson("/api/company/roles/{$systemRole->id}");

        $response->assertStatus(409);
    }
}
