<?php

namespace App\Modules\Infrastructure\Webhooks\Http;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider-specific webhook endpoint with idempotency.
 * POST /api/webhooks/payments/{providerKey}
 * No auth — verified by provider-specific signature.
 */
class PaymentWebhookController
{
    public function __invoke(string $providerKey, Request $request): JsonResponse
    {
        // Verify provider exists and is active
        $module = PlatformPaymentModule::where('provider_key', $providerKey)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->first();

        if (!$module) {
            return response()->json(['message' => 'Unknown or inactive payment provider.'], 404);
        }

        $payload = $request->all();
        $eventId = $payload['id'] ?? $request->header('X-Event-Id', uniqid('evt_'));
        $eventType = $payload['type'] ?? $request->header('X-Event-Type', 'unknown');

        // Idempotency check: try to insert, catch duplicate
        try {
            $webhookEvent = WebhookEvent::create([
                'provider_key' => $providerKey,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'received',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Already processed — return 200 without reprocessing
            return response()->json(['handled' => true, 'duplicate' => true]);
        }

        $adapter = static::resolveAdapter($providerKey);

        if (!$adapter) {
            $webhookEvent->update([
                'status' => 'failed',
                'error_message' => 'No adapter available.',
            ]);

            return response()->json(['handled' => false, 'error' => 'No adapter.'], 422);
        }

        try {
            $webhookEvent->update(['status' => 'processing']);

            $result = $adapter->handleWebhookEvent($payload, $request->headers->all());

            $webhookEvent->update([
                'status' => $result->handled ? 'processed' : 'failed',
                'processed_at' => $result->handled ? now() : null,
                'error_message' => $result->error,
            ]);

            return response()->json([
                'handled' => $result->handled,
                'action' => $result->action,
            ]);
        } catch (\Throwable $e) {
            $webhookEvent->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['handled' => false], 500);
        }
    }

    private static function resolveAdapter(string $providerKey): ?PaymentProviderAdapter
    {
        return match ($providerKey) {
            'internal' => new InternalPaymentAdapter(),
            'stripe' => new StripePaymentAdapter(),
            default => null,
        };
    }
}
