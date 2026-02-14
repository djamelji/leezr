<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCompanyUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function createSuperAdmin(): PlatformUser
    {
        $user = PlatformUser::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super@platform.test',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $user->roles()->attach($superAdmin);

        return $user;
    }

    private function createCompanyUser(): User
    {
        $user = User::create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'jean@company.test',
            'password' => 'P@ssw0rd!Strong',
            'password_set_at' => now(),
        ]);

        $company = Company::create(['name' => 'Test Company', 'slug' => 'test-company']);
        $user->companies()->attach($company, ['role' => 'owner']);

        return $user;
    }

    public function test_platform_admin_sees_display_name(): void
    {
        $admin = $this->createSuperAdmin();
        $companyUser = $this->createCompanyUser();

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/company-users');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data[0]['display_name']);
        $this->assertEquals('Jean Dupont', $data[0]['display_name']);
    }

    public function test_platform_company_users_index_returns_first_and_last_name(): void
    {
        $admin = $this->createSuperAdmin();
        $companyUser = $this->createCompanyUser();

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/company-users');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals('Jean', $data[0]['first_name']);
        $this->assertEquals('Dupont', $data[0]['last_name']);
        $this->assertEquals('Jean Dupont', $data[0]['display_name']);
    }

    public function test_platform_admin_sees_user_status(): void
    {
        $admin = $this->createSuperAdmin();
        $companyUser = $this->createCompanyUser();

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/company-users');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('status', $data[0]);
        $this->assertEquals('active', $data[0]['status']);
    }

    public function test_requires_view_company_users_permission(): void
    {
        $user = PlatformUser::create([
            'first_name' => 'No',
            'last_name' => 'Perms',
            'email' => 'noperms@platform.test',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // No role attached → no permissions

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/company-users');

        $response->assertStatus(403);
    }
}
