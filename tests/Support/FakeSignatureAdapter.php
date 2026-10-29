<?php

namespace Tests\Support;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\Contracts\SignatureProviderAdapter;
use App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome;

/**
 * Prestataire de signature FICTIF pour les tests : certifié, non DEV-only, webhooks vérifiables.
 * verifyWebhookSignature exige l'en-tête X-Fake-Signature: valid.
 */
class FakeSignatureAdapter implements SignatureProviderAdapter
{
    public const KEY = 'fake';

    public bool $evidenceValid = true;

    public function key(): string
    {
        return self::KEY;
    }

    public function isCertified(): bool
    {
        return true;
    }

    public function isDevOnly(): bool
    {
        return false;
    }

    public function supportedLevels(string $marketKey): array
    {
        return [SignatureRequest::LEVEL_SIMPLE, SignatureRequest::LEVEL_ADVANCED, SignatureRequest::LEVEL_QUALIFIED];
    }

    public function supportsLevel(string $marketKey, string $level): bool
    {
        return in_array($level, $this->supportedLevels($marketKey), true);
    }

    public function createEnvelope(SignatureRequest $request, array $signers): array
    {
        $refs = [];
        foreach ($signers as $s) {
            $refs[$s->id] = 'fake-sig-' . $s->id;
        }

        return ['provider_reference' => 'fake-env-' . $request->id, 'signer_references' => $refs, 'certified' => true];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $sig = $headers['x-fake-signature'][0] ?? ($headers['X-Fake-Signature'][0] ?? null);
        if ($sig !== 'valid') {
            throw new \RuntimeException('Invalid fake signature.');
        }
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookOutcome
    {
        if (($payload['type'] ?? null) === 'signer.signed') {
            return new WebhookOutcome(handled: true, signerReference: $payload['signer_reference'] ?? null, action: 'signed');
        }
        if (($payload['type'] ?? null) === 'signer.declined') {
            return new WebhookOutcome(handled: true, signerReference: $payload['signer_reference'] ?? null, action: 'declined', metadata: ['reason' => $payload['reason'] ?? null]);
        }

        return WebhookOutcome::ignored();
    }

    public function verifyEvidence(SignatureRequest $request): bool
    {
        return $this->evidenceValid;
    }

    public function fetchFinalDocument(SignatureRequest $request): ?string
    {
        return null; // fallback = copie du source
    }
}
