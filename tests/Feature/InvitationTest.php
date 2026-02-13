<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_store_creates_user_if_not_found(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/company/members', [
                'email' => 'invited@example.com',
                'role' => 'user',
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
        $owner = User::factory()->create();
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/company/members', [
                'email' => 'existing@example.com',
                'role' => 'admin',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('member.user.status', 'active');
    }
}
