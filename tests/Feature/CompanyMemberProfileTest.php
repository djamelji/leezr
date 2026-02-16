<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

class CompanyMemberProfileTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac;

    private User $owner;
    private User $admin;
    private User $member;
    private Company $company;
    private $ownerMembership;
    private $adminMembership;
    private $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->ownerMembership = $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->adminMembership = $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'admin', 'company_role_id' => $adminRole->id]);
        $this->memberMembership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => 'user']);

        // Activate company_user fields
        $cuFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();

        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 0) Members index returns 200 with expected structure ──

    public function test_company_members_index_returns_200(): void
    {
        $response = $this->actAs($this->member)
            ->getJson('/api/company/members');

        $response->assertOk()
            ->assertJsonStructure([
                'members' => [
                    '*' => [
                        'id',
                        'user' => ['id', 'first_name', 'last_name', 'display_name', 'email', 'status'],
                        'role',
                        'company_role',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('members'));
    }

    // ─── 1) Member can view member profile ────────────────

    public function test_member_can_view_member_profile(): void
    {
        $response = $this->actAs($this->member)
            ->getJson("/api/company/members/{$this->adminMembership->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'member' => ['id', 'role', 'created_at'],
                'base_fields' => ['id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'],
                'dynamic_fields',
            ]);

        $this->assertEquals($this->admin->first_name, $response->json('base_fields.first_name'));
    }

    // ─── 2) Admin can update base fields (Bloc A) ────────

    public function test_admin_can_update_member_base_fields(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Name',
            ]);

        $response->assertOk();
        $this->assertEquals('Updated', $response->json('base_fields.first_name'));
        $this->assertEquals('Name', $response->json('base_fields.last_name'));

        $this->member->refresh();
        $this->assertEquals('Updated', $this->member->first_name);
        $this->assertEquals('Name', $this->member->last_name);
    }

    // ─── 3) Admin can update dynamic fields (Bloc B) ──────

    public function test_admin_can_update_member_dynamic_fields(): void
    {
        $phone = FieldDefinition::where('code', 'phone')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->first();

        if (!$phone) {
            $this->markTestSkipped('No phone field defined for company_user scope');
        }

        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'dynamic_fields' => [
                    'phone' => '+33 1 23 45 67 89',
                ],
            ]);

        $response->assertOk();

        $phoneField = collect($response->json('dynamic_fields'))
            ->firstWhere('code', 'phone');

        $this->assertEquals('+33 1 23 45 67 89', $phoneField['value']);
    }

    // ─── 4) Admin can update company role (Bloc C) ────────

    public function test_admin_can_update_member_company_role(): void
    {
        $adminRole = \App\Company\RBAC\CompanyRole::where('company_id', $this->company->id)
            ->where('key', 'admin')->first();

        $response = $this->actAs($this->owner)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'company_role_id' => $adminRole->id,
            ]);

        $response->assertOk();
        $this->assertEquals('admin', $response->json('member.company_role.key'));
    }

    // ─── 5) Non-admin cannot update member profile ────────

    public function test_non_admin_cannot_update_member_profile(): void
    {
        $response = $this->actAs($this->member)
            ->putJson("/api/company/members/{$this->adminMembership->id}", [
                'first_name' => 'Hacked',
            ]);

        $response->assertStatus(403);
    }

    // ─── 6) Partial update preserves dynamic fields ───────

    public function test_partial_update_preserves_dynamic_fields(): void
    {
        $phone = FieldDefinition::where('code', 'phone')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->first();

        if (!$phone) {
            $this->markTestSkipped('No phone field defined for company_user scope');
        }

        // First set dynamic field value
        $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'dynamic_fields' => ['phone' => '+33 1 11 11 11 11'],
            ])
            ->assertOk();

        // Then update only base fields — dynamic should be preserved
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'first_name' => 'Changed',
            ]);

        $response->assertOk();
        $this->assertEquals('Changed', $response->json('base_fields.first_name'));

        $phoneField = collect($response->json('dynamic_fields'))
            ->firstWhere('code', 'phone');

        $this->assertEquals('+33 1 11 11 11 11', $phoneField['value']);
    }

    // ─── 7) Query count is constant ──────────────────────

    public function test_member_profile_query_count_is_constant(): void
    {
        DB::enableQueryLog();

        $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}")
            ->assertOk();

        $queryCount = count(DB::getQueryLog());

        DB::flushQueryLog();

        // Query count should be reasonable (no N+1)
        // Typical: auth check + company context + membership + user + field definitions + activations + values
        $this->assertLessThanOrEqual(15, $queryCount, "ReadModel generated {$queryCount} queries — check for N+1");
    }
}
