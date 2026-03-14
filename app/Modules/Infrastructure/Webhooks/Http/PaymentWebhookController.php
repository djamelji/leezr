<?php

namespace App\Modules\Infrastructure\Webhooks\Http;

use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Provider-specific webhook endpoint with idempotency.
 * POST /api/webhooks/payments/{providerKey}
 * No auth — verified by provider-specific signature (ADR-137).
 */
class PaymentWebhookController
{
    public function __invoke(string $providerKey, Request $request): JsonResponse
    {
        // 1. Verify provider exists and is active
        $module = PlatformPaymentModule::where('provider_key', $providerKey)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->first();

        if (! $module) {
            return response()->json(['message' => 'Unknown or inactive payment provider.'], 404);
        }

        // 2. Resolve adapter early (needed for signature verification)
        $adapter = PaymentGatewayManager::adapterFor($providerKey);

        if (! $adapter) {
            return response()->json(['handled' => false, 'error' => 'No adapter.'], 422);
        }

        // 3. Verify webhook signature (before parsing payload)
        $rawBody = $request->getContent();

        try {
            $adapter->verifyWebhookSignature($rawBody, $request->headers->all());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        // 4. Parse payload (after signature verification)
        $payload = $request->all();

        // 5. Require event_id — no fallback generation
        $eventId = $payload['id'] ?? null;
        if (! $eventId) {
            return response()->json(['message' => 'Missing event ID in payload.'], 400);
        }

        // 6. Require event_type — no fallback
        $eventType = $payload['type'] ?? null;
        if (! $eventType) {
            return response()->json(['message' => 'Missing event type in payload.'], 400);
        }

        // 7. Idempotency check: try to insert, catch duplicate
        try {
            $webhookEvent = WebhookEvent::create([
                'provider_key' => $providerKey,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'received',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['handled' => true, 'duplicate' => true]);
        }

        // 8. Process within transaction
        try {
            DB::transaction(function () use ($adapter, $webhookEvent, $payload, $request) {
                $webhookEvent->update(['status' => 'processing']);

                $result = $adapter->handleWebhookEvent($payload, $request->headers->all());

                $webhookEvent->update([
                    'status' => $result->handled ? 'processed' : 'ignored',
                    'processed_at' => $result->handled ? now() : null,
                    'error_message' => $result->error,
                ]);
            });

            $webhookEvent->refresh();

            return response()->json([
                'handled' => $webhookEvent->status === 'processed',
                'action' => $webhookEvent->status,
            ]);
        } catch (\Throwable $e) {
            $webhookEvent->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // ADR-228: Dead letter — persist for later replay, always return 200
            BillingWebhookDeadLetter::create([
                'provider_key' => $providerKey,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            // Always return 200 so Stripe does not retry (we handle replays ourselves)
            return response()->json(['handled' => false, 'dead_lettered' => true]);
        }
    }

}
