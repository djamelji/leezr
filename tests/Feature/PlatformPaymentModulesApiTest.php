<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPaymentModulesApiTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    public function test_unauthenticated_blocked(): void
    {
        $response = $this->getJson('/api/platform/billing/payment-modules');

        $response->assertStatus(401);
    }

    public function test_list_modules(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/payment-modules');

        $response->assertOk()
            ->assertJsonStructure(['modules']);

        $this->assertIsArray($response->json('modules'));
    }

    public function test_install_known_provider(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/install');

        $response->assertOk()
            ->assertJsonPath('message', 'Payment module installed.')
            ->assertJsonPath('module.is_installed', true);
    }

    public function test_activate_requires_installed(): void
    {
        // Create a module row that is NOT installed
        PlatformPaymentModule::create([
            'provider_key' => 'internal',
            'name' => 'Internal (No Payment)',
            'is_installed' => false,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/activate');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Module must be installed before activation.');
    }

    public function test_activate_installed_module(): void
    {
        // Install first
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/install');

        // Then activate
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/activate');

        $response->assertOk()
            ->assertJsonPath('message', 'Payment module activated.')
            ->assertJsonPath('module.is_active', true);
    }

    public function test_deactivate_module(): void
    {
        // Install and activate first
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/install');

        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/activate');

        // Deactivate
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/deactivate');

        $response->assertOk()
            ->assertJsonPath('message', 'Payment module deactivated.')
            ->assertJsonPath('module.is_active', false);
    }

    public function test_credentials_update_encrypted(): void
    {
        // Install first
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/install');

        // Update credentials
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/credentials', [
                'credentials' => ['secret_key' => 'sk_test_123'],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Credentials updated.')
            ->assertJsonPath('has_credentials', true);

        // Verify credentials are stored (encrypted column has a non-null value)
        $module = PlatformPaymentModule::where('provider_key', 'internal')->first();
        $this->assertNotNull($module->getRawOriginal('credentials'));
        $this->assertEquals(['secret_key' => 'sk_test_123'], $module->credentials);
    }

    public function test_health_check_internal(): void
    {
        // Install first so the module row exists
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/internal/install');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/payment-modules/internal/health');

        $response->assertOk()
            ->assertJsonPath('health.status', 'healthy')
            ->assertJsonStructure(['health', 'checked_at']);
    }

    public function test_install_unknown_provider_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/billing/payment-modules/nonexistent/install');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Unknown payment provider.');
    }
}
