<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;

/**
 * W-SIGNATURE-1 (ADR-651) — journalisation append-only des preuves de signature.
 */
class SignatureEventRecorder
{
    /**
     * @param array{signer?:?SignatureSigner, actorType?:string, actorId?:?int, documentSha256?:?string, providerReference?:?string, verification?:?string, payload?:array} $options
     */
    public function record(SignatureRequest $request, string $type, array $options = []): SignatureEvent
    {
        $signer = $options['signer'] ?? null;

        return SignatureEvent::create([
            'company_id' => $request->company_id,
            'signature_request_id' => $request->id,
            'signature_signer_id' => $signer?->id,
            'type' => $type,
            'actor_type' => $options['actorType'] ?? SignatureEvent::ACTOR_SYSTEM,
            'actor_id' => $options['actorId'] ?? null,
            'document_sha256' => $options['documentSha256'] ?? $request->document_sha256,
            'provider_reference' => $options['providerReference'] ?? $request->provider_reference,
            'verification_result' => $options['verification'] ?? null,
            'payload' => $options['payload'] ?? null,
            'occurred_at' => now(),
        ]);
    }
}
