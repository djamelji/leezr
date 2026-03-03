<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private CompanyRole $userRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co', 'jobdomain_key' => 'logistique']);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->company);

        $this->userRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'user_role',
            'name' => 'User',
            'is_administrative' => false,
        ]);
    }

    public function test_membership_store_creates_user_if_not_found(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/members', [
                'email' => 'invited@example.com',
                'company_role_id' => $this->userRole->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('member.user.status', 'invited');

        // User was created with null password and null password_set_at
        $invitedUser = User::where('email', 'invited@example.com')->first();
        $this->assertNotNull($invitedUser);
        $this->assertNull($invitedUser->getRawOriginal('password'));
        $this->assertNull($invitedUser->password_set_at);
    }

    public function test_membership_store_uses_existing_user(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/members', [
                'email' => 'existing@example.com',
                'company_role_id' => $this->userRole->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('member.user.status', 'active');
    }

    public function test_membership_store_rejects_without_company_role(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/members', [
                'email' => 'nope@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('company_role_id');
    }
}
