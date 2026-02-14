<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_company(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Test Company',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user', 'company']);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'first_name' => 'Test', 'last_name' => 'User']);
        $this->assertDatabaseHas('companies', ['name' => 'Test Company']);
    }

    public function test_register_regenerates_session(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Test Company',
        ]);

        $response->assertStatus(201);
        $this->assertAuthenticated('web');
    }

    public function test_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user']);

        $this->assertAuthenticated('web');
    }

    public function test_login_with_invalid_credentials_returns_401(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertOk();
        $this->assertGuest('web');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_me_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_forgot_password_always_returns_success(): void
    {
        $this->get('/sanctum/csrf-cookie');

        // Existing email
        User::factory()->create(['email' => 'user@example.com']);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $response->assertOk();

        // Non-existing email — same response (anti-enumeration)
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk();
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'company_name' => 'Test Co',
        ]);

        $response->assertStatus(422);
    }
}
