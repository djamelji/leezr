<?php

namespace Tests\Feature;

use App\Core\Ai\AiRequestLog;
use App\Core\Ai\PlatformAiModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'ai-test-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ── Read endpoints (view_ai) ──────────────────────────────

    public function test_providers_endpoint_returns_list(): void
    {
        // Seed a provider module so the list is non-empty
        PlatformAiModule::create([
            'provider_key' => 'null',
            'name' => 'Null (No-op)',
            'description' => 'Stub provider for testing',
            'is_installed' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/ai/providers');

        $response->assertOk()
            ->assertJsonStructure(['providers']);

        // Find the 'null' provider in the merged list (DB + registry)
        $providers = collect($response->json('providers'));
        $nullProvider = $providers->firstWhere('provider_key', 'null');
        $this->assertNotNull($nullProvider);
        $this->assertTrue($nullProvider['is_active']);
        $this->assertArrayHasKey('configuration_status', $nullProvider);
        $this->assertArrayHasKey('supported_capabilities', $nullProvider);
    }

    public function test_usage_endpoint_returns_stats(): void
    {
        // Seed a few request logs
        AiRequestLog::create([
            'provider' => 'null',
            'model' => 'none',
            'capability' => 'completion',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 200,
            'status' => 'success',
        ]);

        AiRequestLog::create([
            'provider' => 'null',
            'model' => 'none',
            'capability' => 'completion',
            'input_tokens' => 80,
            'output_tokens' => 40,
            'latency_ms' => 0,
            'status' => 'error',
            'error_message' => 'Test error',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/ai/usage');

        $response->assertOk()
            ->assertJsonStructure([
                'stats' => ['total_requests', 'successful', 'errors', 'avg_latency_ms', 'total_input_tokens', 'total_output_tokens'],
                'by_provider',
                'by_module',
                'recent_requests',
                'period',
            ]);

        $stats = $response->json('stats');
        $this->assertEquals(2, $stats['total_requests']);
        $this->assertEquals(1, $stats['successful']);
        $this->assertEquals(1, $stats['errors']);
        $this->assertEquals(180, $stats['total_input_tokens']);
        $this->assertEquals(90, $stats['total_output_tokens']);
    }

    // ── Write endpoints (manage_ai) ───────────────────────────

    public function test_activate_provider(): void
    {
        PlatformAiModule::create([
            'provider_key' => 'null',
            'name' => 'Null (No-op)',
            'is_installed' => true,
            'is_active' => false,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/ai/providers/null/activate');

        $response->assertOk()
            ->assertJsonFragment(['is_active' => true]);

        $this->assertTrue(PlatformAiModule::where('provider_key', 'null')->first()->is_active);
    }

    public function test_health_check_provider(): void
    {
        PlatformAiModule::create([
            'provider_key' => 'null',
            'name' => 'Null (No-op)',
            'is_installed' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/ai/providers/null/health-check');

        $response->assertOk()
            ->assertJsonStructure(['health', 'checked_at'])
            ->assertJsonPath('health.status', 'healthy');

        // Verify health status was persisted
        $module = PlatformAiModule::where('provider_key', 'null')->first();
        $this->assertEquals('healthy', $module->health_status);
        $this->assertNotNull($module->health_checked_at);
    }

    // ── Auth / authorization guards ───────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/platform/ai/providers');

        $response->assertStatus(401);
    }
}
