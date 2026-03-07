<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\WebhookEvent;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StripeWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        config(['billing.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    // ── Helpers ──────────────────────────────────────────

    private function stripePayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            'data' => ['object' => ['id' => 'pi_test']],
        ], $overrides));
    }

    private function validSignatureHeader(string $payload, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, self::WEBHOOK_SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    private function postStripeWebhook(string $payload, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/webhooks/payments/stripe',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }

    // ── Tests ────────────────────────────────────────────

    public function test_rejects_invalid_signature(): void
    {
        $payload = $this->stripePayload();

        $response = $this->postStripeWebhook($payload, 't=9999,v1=bad_signature');

        $response->assertStatus(400);
    }

    public function test_accepts_old_event_timestamp(): void
    {
        // ADR-228: stale rejection removed — old events are accepted for recovery
        $eventId = 'evt_old_'.uniqid();
        $payload = $this->stripePayload(['id' => $eventId, 'created' => time() - 600]);
        $signature = $this->validSignatureHeader($payload);

        $response = $this->postStripeWebhook($payload, $signature);

        $response->assertOk();
        $this->assertDatabaseHas('webhook_events', [
            'provider_key' => 'stripe',
            'event_id' => $eventId,
        ]);
    }

    public function test_rejects_missing_event_id(): void
    {
        $data = [
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            'data' => ['object' => ['id' => 'pi_test']],
        ];
        $payload = json_encode($data);
        $signature = $this->validSignatureHeader($payload);

        $response = $this->postStripeWebhook($payload, $signature);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Missing event ID in payload.']);
    }

    public function test_accepts_valid_signature(): void
    {
        $eventId = 'evt_valid_'.uniqid();
        $payload = $this->stripePayload(['id' => $eventId]);
        $signature = $this->validSignatureHeader($payload);

        $response = $this->postStripeWebhook($payload, $signature);

        $response->assertOk();
        $this->assertDatabaseHas('webhook_events', [
            'provider_key' => 'stripe',
            'event_id' => $eventId,
        ]);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $eventId = 'evt_dup_'.uniqid();
        $payload = $this->stripePayload(['id' => $eventId]);
        $signature = $this->validSignatureHeader($payload);

        $first = $this->postStripeWebhook($payload, $signature);
        $first->assertOk();

        $second = $this->postStripeWebhook($payload, $signature);
        $second->assertOk();
        $second->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_refund_calls_stripe_api_success(): void
    {
        $adapter = new class extends StripePaymentAdapter
        {
            protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
            {
                return \Stripe\Refund::constructFrom([
                    'id' => 're_test_123',
                    'amount' => $amount,
                    'status' => 'succeeded',
                ]);
            }
        };

        $result = $adapter->refund('pi_test', 1000);

        $this->assertEquals('re_test_123', $result['provider_refund_id']);
        $this->assertEquals(1000, $result['amount']);
        $this->assertEquals('succeeded', $result['status']);
        $this->assertIsArray($result['raw_response']);
    }

    public function test_refund_handles_stripe_exception(): void
    {
        $adapter = new class extends StripePaymentAdapter
        {
            protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
            {
                throw \Stripe\Exception\InvalidRequestException::factory(
                    'No such payment_intent',
                    null,
                    null,
                    null,
                    null,
                    'invalid_request_error',
                );
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe refund failed');

        $adapter->refund('pi_nonexistent', 500);
    }
}
