<?php

namespace App\Modules\Infrastructure\Webhooks\Http;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
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
        $adapter = static::resolveAdapter($providerKey);

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

        // 7. Reject stale events (older than 5 minutes)
        $eventCreated = $payload['created'] ?? null;
        if ($eventCreated && $eventCreated < time() - 300) {
            return response()->json(['message' => 'Event too old.'], 400);
        }

        // 8. Idempotency check: try to insert, catch duplicate
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

        // 9. Process within transaction
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
