<?php

namespace App\Modules\Workforce\Signature\Providers\Contracts;

use App\Modules\Workforce\Signature\Models\SignatureRequest;

/**
 * ADR-767 — capability of a provider that SEALS the final document on its side (real external vendor).
 *
 * For such a provider the signed artefact is not produced locally: it must be FETCHED from the provider,
 * validated, archived, and only then can the evidence be verified against it. An adapter that does not
 * implement this capability keeps the historical finalisation path (local seal).
 */
interface SealsDocumentRemotely
{
    /**
     * True when the provider is really reachable (credentials configured) but has NOT yet announced
     * where the sealed document is. The request must then wait for the provider's completion event
     * instead of being finalised (which could only fail). Always false in sandbox: nothing will ever
     * be delivered, so finalisation runs and fails on unverified evidence (no fake completion).
     */
    public function awaitsSealedDocument(SignatureRequest $request): bool;
}
