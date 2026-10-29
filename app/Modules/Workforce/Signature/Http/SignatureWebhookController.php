<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Models\SignatureWebhookEvent;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\UseCases\RecordSignerActionUseCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * W-SIGNATURE-1 (ADR-651) — réception des webhooks prestataire.
 *
 * Fail-closed : prestataire inconnu → 404 ; signature invalide → 400 (vérifiée sur le corps BRUT
 * AVANT parsing) ; idempotent (insert unique provider+event_id) ; résistant au désordre (on n'applique
 * jamais une régression d'état illégale, la machine à états rejette). Un événement ne peut pas
 * finaliser une demande d'une AUTRE Company (résolution par référence prestataire liée à la demande).
 */
class SignatureWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $adapter = SignatureProviderManager::adapterFor($provider);
        if ($adapter === null) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        // 1. Vérification de signature sur le corps BRUT (fail-closed).
        $rawBody = $request->getContent();
        try {
            $adapter->verifyWebhookSignature($rawBody, $request->headers->all());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($rawBody, true) ?: [];
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;
        if (! $eventId || ! $eventType) {
            return response()->json(['message' => 'Missing event id or type.'], 400);
        }

        // 2. Idempotence par insert unique.
        try {
            $webhookEvent = SignatureWebhookEvent::create([
                'provider' => $provider, 'event_id' => (string) $eventId,
                'event_type' => (string) $eventType, 'payload' => $payload,
                'status' => SignatureWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['handled' => true, 'duplicate' => true]);
        }

        // 3. Traitement idempotent ; toujours 200 (les régressions illégales sont rejetées en interne).
        try {
            DB::transaction(function () use ($adapter, $payload, $request, $webhookEvent) {
                $webhookEvent->update(['status' => SignatureWebhookEvent::STATUS_PROCESSING]);
                $outcome = $adapter->handleWebhookEvent($payload, $request->headers->all());

                if (! $outcome->handled) {
                    $webhookEvent->update(['status' => SignatureWebhookEvent::STATUS_IGNORED]);

                    return;
                }
                $this->applyOutcome($outcome);
                $webhookEvent->update(['status' => SignatureWebhookEvent::STATUS_PROCESSED]);
            });
        } catch (\Throwable $e) {
            $webhookEvent->update(['status' => SignatureWebhookEvent::STATUS_FAILED]);
            Log::error('Signature webhook processing failed', ['provider' => $provider, 'event_id' => $eventId, 'error' => $e->getMessage()]);
            // 200 : pas de retry provider ; rejeu interne possible.
        }

        return response()->json(['handled' => true]);
    }

    private function applyOutcome(\App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome $outcome): void
    {
        if (! $outcome->signatureRequestId && ! $outcome->signerReference) {
            return;
        }

        // Résout le signataire par référence prestataire (liée à sa propre demande → pas de cross-tenant).
        if ($outcome->signerReference) {
            $signer = SignatureSigner::withoutGlobalScopes()
                ->where('evidence_reference', $outcome->signerReference)->first();
            if (! $signer) {
                return;
            }
            // L'action webhook est appliquée via la machine à états (rejette tout état illégal / désordre).
            if ($outcome->action === 'signed' && ! $signer->isActed()) {
                app(RecordSignerActionUseCase::class)->signByServerAuthority($signer, 'provider_webhook');
            } elseif ($outcome->action === 'declined' && ! $signer->isActed()) {
                app(RecordSignerActionUseCase::class)->declineByServerAuthority($signer, $outcome->metadata['reason'] ?? null, 'provider_webhook');
            }
        }
    }
}
