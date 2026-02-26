<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPaymentRulesApiTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

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

    // ─── LIST ────────────────────────────────────────────

    public function test_list_rules(): void
    {
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'priority' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/payment-rules');

        $response->assertOk()
            ->assertJsonStructure(['rules' => [['id', 'method_key', 'provider_key', 'priority', 'is_active']]]);

        $this->assertCount(1, $response->json('rules'));
    }

    // ─── CREATE ──────────────────────────────────────────

    public function test_create_rule_all_dimensions(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/billing/payment-rules', [
                'method_key' => 'card',
                'provider_key' => 'internal',
                'market_key' => 'FR',
                'plan_key' => 'pro',
                'interval' => 'monthly',
                'priority' => 50,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'rule'])
            ->assertJsonPath('rule.method_key', 'card')
            ->assertJsonPath('rule.provider_key', 'internal')
            ->assertJsonPath('rule.market_key', 'FR')
            ->assertJsonPath('rule.plan_key', 'pro')
            ->assertJsonPath('rule.interval', 'monthly');
    }

    public function test_create_rule_null_dimensions(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/billing/payment-rules', [
                'method_key' => 'manual',
                'provider_key' => 'internal',
                'market_key' => null,
                'plan_key' => null,
                'interval' => null,
                'priority' => 0,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'rule'])
            ->assertJsonPath('rule.market_key', null)
            ->assertJsonPath('rule.plan_key', null)
            ->assertJsonPath('rule.interval', null);
    }

    // ─── UPDATE ──────────────────────────────────────────

    public function test_update_rule(): void
    {
        $rule = PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'priority' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/billing/payment-rules/{$rule->id}", [
                'priority' => 99,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'rule'])
            ->assertJsonPath('rule.priority', 99);
    }

    // ─── DELETE ──────────────────────────────────────────

    public function test_delete_rule(): void
    {
        $rule = PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'priority' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/billing/payment-rules/{$rule->id}");

        $response->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('platform_payment_method_rules', ['id' => $rule->id]);
    }

    // ─── UNIQUE CONSTRAINT ───────────────────────────────

    public function test_duplicate_unique_constraint_rejected(): void
    {
        $payload = [
            'method_key' => 'card',
            'provider_key' => 'internal',
            'market_key' => 'FR',
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'priority' => 10,
            'is_active' => true,
        ];

        $first = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/billing/payment-rules', $payload);

        $first->assertStatus(201);

        $second = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/billing/payment-rules', $payload);

        // Should be rejected — either 422 validation or 500 DB integrity error
        $this->assertTrue(
            in_array($second->status(), [422, 500], true),
            "Expected 422 or 500, got {$second->status()}",
        );
    }

    // ─── PREVIEW ─────────────────────────────────────────

    public function test_preview_returns_methods(): void
    {
        // Create a rule targeting FR market
        PlatformPaymentMethodRule::create([
            'method_key' => 'manual',
            'provider_key' => 'internal',
            'market_key' => 'FR',
            'plan_key' => null,
            'interval' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        // Create the corresponding payment module (installed + active)
        PlatformPaymentModule::create([
            'provider_key' => 'internal',
            'name' => 'Internal (No Payment)',
            'is_installed' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/payment-rules/preview?market_key=FR');

        $response->assertOk()
            ->assertJsonStructure(['methods']);

        $methods = $response->json('methods');
        $this->assertNotEmpty($methods);

        $methodKeys = array_column($methods, 'method_key');
        $this->assertContains('manual', $methodKeys);
    }

    // ─── VALIDATION ──────────────────────────────────────

    public function test_invalid_provider_key_rejected(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/billing/payment-rules', [
                'method_key' => 'card',
                'provider_key' => 'nonexistent',
                'priority' => 10,
                'is_active' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider_key']);
    }
}
