<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Tenancy\CompanyContext;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome;

/**
 * ADR-767 — applies an AUTHENTICATED provider webhook outcome to exactly one request.
 *
 * Reference resolution is the tenant-isolation boundary of the webhook (no tenant is bound there):
 *  - a request is only ever looked up among the requests of THE SAME provider ;
 *  - a signer reference is only accepted inside its own request ; when the event names an envelope,
 *    the signer MUST belong to that envelope's request ;
 *  - any inconsistency or ambiguity (unknown envelope, reference shared by several requests) → NO effect.
 *
 * Supported actions: signed / declined (signer level), completed / failed (envelope level). Every state
 * change goes through the existing state machine and use cases — nothing is forced.
 */
class ApplyProviderWebhookOutcomeUseCase
{
    public function __construct(
        private readonly RecordSignerActionUseCase $signerActions,
        private readonly FinalizeSignatureRequestUseCase $finalize,
    ) {}

    public function execute(string $provider, WebhookOutcome $outcome): void
    {
        // An envelope reference was provided but matches no request of this provider → refuse.
        $namedEnvelope = ! empty($outcome->metadata['provider_reference']);
        if ($namedEnvelope && ! $outcome->signatureRequestId) {
            return;
        }

        $request = null;
        if ($outcome->signatureRequestId) {
            $request = SignatureRequest::withoutGlobalScopes()
                ->where('provider', $provider)->where('id', $outcome->signatureRequestId)
                ->lockForUpdate()->first();
            if (! $request) {
                return;
            }
        }

        $signer = null;
        if ($outcome->signerReference) {
            $signer = $this->resolveSigner($provider, $outcome->signerReference, $request);
            if (! $signer) {
                return; // unknown, foreign or ambiguous reference
            }
            $request ??= SignatureRequest::withoutGlobalScopes()
                ->where('provider', $provider)->where('id', $signer->signature_request_id)
                ->lockForUpdate()->first();
        }
        if (! $request) {
            return;
        }

        // The webhook has no tenant bound: everything below runs inside the request's OWN tenant.
        CompanyContext::runFor((int) $request->company_id, fn () => $this->apply($request, $signer, $outcome));
    }

    private function apply(SignatureRequest $request, ?SignatureSigner $signer, WebhookOutcome $outcome): void
    {
        $this->rememberSealedDocument($request, $outcome);

        match ($outcome->action) {
            'signed' => $signer && ! $signer->isActed()
                ? $this->signerActions->signByServerAuthority($signer, 'provider_webhook') : null,
            'declined' => $signer && ! $signer->isActed()
                ? $this->signerActions->declineByServerAuthority($signer, $outcome->metadata['reason'] ?? null, 'provider_webhook') : null,
            'completed' => $this->completeIfAllSigned($request),
            'failed' => $this->finalize->failFromProvider($request->fresh(), $outcome->metadata['reason'] ?? null),
            default => null,
        };
    }

    /** Exactly one signer of THIS provider (and of the named request, if any) — otherwise null. */
    private function resolveSigner(string $provider, string $reference, ?SignatureRequest $request): ?SignatureSigner
    {
        $matches = SignatureSigner::withoutGlobalScopes()
            ->where('evidence_reference', $reference)
            ->when($request, fn ($q) => $q->where('signature_request_id', $request->id))
            ->whereIn('signature_request_id', SignatureRequest::withoutGlobalScopes()->where('provider', $provider)->select('id'))
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Persists where the provider says the sealed document is (and its attested hash). */
    private function rememberSealedDocument(SignatureRequest $request, WebhookOutcome $outcome): void
    {
        $url = $outcome->metadata['final_document_url'] ?? null;
        if (! is_string($url) || $url === '' || ! $request->isActive()) {
            return;
        }
        $meta = ['final_document_url' => $url];
        $sha = $outcome->metadata['final_document_sha256'] ?? null;
        if (is_string($sha) && $sha !== '') {
            $meta['final_document_sha256'] = $sha;
        }
        $request->metadata = array_merge($request->metadata ?? [], $meta);
        $request->save();
    }

    /** The provider's "completed" never completes a request whose signers have not all signed. */
    private function completeIfAllSigned(SignatureRequest $request): ?SignatureRequest
    {
        $request = $request->fresh();
        if (! $request->isActive()) {
            return null;
        }
        $remaining = $request->signers()->whereNotIn('status', [SignatureSigner::STATUS_SIGNED])->count();
        if ($remaining > 0 || $this->finalize->awaitsProviderDocument($request)) {
            return null;
        }

        return $this->finalize->execute($request);
    }

    /**
     * ADR-848 (Leezr 4.2 M9) — process one RECORDED webhook event atomically, moved verbatim from
     * SignatureWebhookController : processing → (ignored | outcome applied → processed). The caller marks the event
     * failed and logs if this throws (200 to the provider : no retry, internal replay possible).
     */
    public function processEvent(object $adapter, array $payload, array $headers, \App\Modules\Workforce\Signature\Models\SignatureWebhookEvent $webhookEvent, string $provider): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($adapter, $payload, $headers, $webhookEvent, $provider) {
            $webhookEvent->update(['status' => \App\Modules\Workforce\Signature\Models\SignatureWebhookEvent::STATUS_PROCESSING]);
            $outcome = $adapter->handleWebhookEvent($payload, $headers);

            if (! $outcome->handled) {
                $webhookEvent->update(['status' => \App\Modules\Workforce\Signature\Models\SignatureWebhookEvent::STATUS_IGNORED]);

                return;
            }
            $this->execute($provider, $outcome);
            $webhookEvent->update(['status' => \App\Modules\Workforce\Signature\Models\SignatureWebhookEvent::STATUS_PROCESSED]);
        });
    }
}
