<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CompanyMemberCredentialTest extends TestCase
{
    use RefreshDatabase;

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
        $this->ownerMembership = $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->adminMembership = $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'admin']);
        $this->memberMembership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => 'user']);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 1) Admin can trigger password reset for member ──

    public function test_admin_can_reset_member_password(): void
    {
        Notification::fake();

        $response = $this->actAs($this->admin)
            ->postJson("/api/company/members/{$this->memberMembership->id}/reset-password");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Password reset link sent to ' . $this->member->email]);
    }

    // ─── 2) Admin can set member password ────────────────

    public function test_admin_can_set_member_password(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertOk();

        $this->member->refresh();
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('Xk9#mW4!qZ7pL2v', $this->member->password),
        );
    }

    // ─── 3) Cannot reset owner password ──────────────────

    public function test_cannot_reset_owner_password(): void
    {
        $response = $this->actAs($this->admin)
            ->postJson("/api/company/members/{$this->ownerMembership->id}/reset-password");

        $response->assertStatus(403);
    }

    // ─── 4) Cannot set owner password ────────────────────

    public function test_cannot_set_owner_password(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->ownerMembership->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertStatus(403);
    }

    // ─── 5) Cannot reset own password via this endpoint ──

    public function test_cannot_reset_own_password(): void
    {
        $response = $this->actAs($this->admin)
            ->postJson("/api/company/members/{$this->adminMembership->id}/reset-password");

        $response->assertStatus(403);
    }

    // ─── 6) Cannot set own password via this endpoint ────

    public function test_cannot_set_own_password(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->adminMembership->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertStatus(403);
    }

    // ─── 7) Non-admin cannot reset member password ───────

    public function test_non_admin_cannot_reset_member_password(): void
    {
        $response = $this->actAs($this->member)
            ->postJson("/api/company/members/{$this->adminMembership->id}/reset-password");

        $response->assertStatus(403);
    }

    // ─── 8) Password validation enforces policy ──────────

    public function test_set_password_validates_policy(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}/password", [
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
