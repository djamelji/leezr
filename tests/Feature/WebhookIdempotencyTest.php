<?php

namespace Tests\Feature;

use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\WebhookEvent;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'internal',
            'name' => 'Internal',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);
    }

    public function test_webhook_stores_event(): void
    {
        $response = $this->postJson('/api/webhooks/payments/internal', [
            'id' => 'evt_123',
            'type' => 'payment.completed',
            'data' => ['amount' => 1000],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'provider_key' => 'internal',
            'event_id' => 'evt_123',
            'event_type' => 'payment.completed',
            'status' => 'failed', // InternalPaymentAdapter returns handled:false
        ]);
    }

    public function test_duplicate_event_returns_200_without_reprocessing(): void
    {
        $payload = [
            'id' => 'evt_dup_456',
            'type' => 'payment.completed',
            'data' => ['amount' => 2000],
        ];

        $first = $this->postJson('/api/webhooks/payments/internal', $payload);
        $first->assertOk();

        $second = $this->postJson('/api/webhooks/payments/internal', $payload);
        $second->assertOk();
        $second->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $response = $this->postJson('/api/webhooks/payments/nonexistent', [
            'id' => 'evt_unknown',
            'type' => 'payment.completed',
            'data' => ['amount' => 500],
        ]);

        $response->assertNotFound();
    }

    public function test_inactive_provider_returns_404(): void
    {
        PlatformPaymentModule::create([
            'provider_key' => 'disabled_gw',
            'name' => 'Disabled Gateway',
            'is_installed' => true,
            'is_active' => false,
            'health_status' => 'healthy',
        ]);

        $response = $this->postJson('/api/webhooks/payments/disabled_gw', [
            'id' => 'evt_inactive',
            'type' => 'payment.completed',
            'data' => ['amount' => 750],
        ]);

        $response->assertNotFound();
    }
}
