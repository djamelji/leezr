<?php

namespace App\Modules\Workforce\Signature\Providers\Adapters;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\Contracts\SignatureProviderAdapter;
use App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome;

/**
 * W-SIGNATURE-1 (ADR-651) — prestataire INTERNE de développement/test.
 *
 * Permet d'exercer de bout en bout les workflows SANS prestataire externe. La signature se fait via
 * les routes signataires sécurisées de l'application (token opaque haché). NE PRODUIT AUCUNE preuve
 * certifiée : jamais de certificat fabriqué, jamais de vérification d'identité certifiée, niveau
 * limité à « simple ». Explicitement DEV-only (bloqué comme prestataire actif en PROD par le manager).
 */
class InternalSignatureAdapter implements SignatureProviderAdapter
{
    public const KEY = 'internal';

    public function key(): string
    {
        return self::KEY;
    }

    public function isCertified(): bool
    {
        return false; // JAMAIS — preuves non certifiées.
    }

    public function isDevOnly(): bool
    {
        return true;
    }

    public function supportedLevels(string $marketKey): array
    {
        // Le prestataire interne ne sait produire qu'une preuve de niveau « simple » non certifiée.
        return [SignatureRequest::LEVEL_SIMPLE];
    }

    public function supportsLevel(string $marketKey, string $level): bool
    {
        return in_array($level, $this->supportedLevels($marketKey), true);
    }

    public function createEnvelope(SignatureRequest $request, array $signers): array
    {
        // Pas d'enveloppe externe : la référence est interne, les signataires signeront via nos routes.
        $providerRef = 'internal-' . $request->id . '-' . substr(hash('sha256', (string) $request->id . $request->document_sha256), 0, 16);
        $signerRefs = [];
        foreach ($signers as $signer) {
            $signerRefs[$signer->id] = 'internal-signer-' . $signer->id;
        }

        return [
            'provider_reference' => $providerRef,
            'signer_references' => $signerRefs,
            'certified' => false,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        // Le prestataire interne n'a pas de webhook externe.
        throw new \RuntimeException('Internal signature provider has no external webhooks.');
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookOutcome
    {
        return WebhookOutcome::ignored();
    }

    public function verifyEvidence(SignatureRequest $request): bool
    {
        // La preuve interne = tous les signataires ont signé via nos routes (vérifié en amont par le
        // FinalizeUseCase) + empreinte source inchangée. L'adaptateur ne prétend à aucune certification.
        return true;
    }

    public function fetchFinalDocument(SignatureRequest $request): ?string
    {
        // Pas de rendu prestataire : le service de finalisation archive une copie du PDF source
        // comme artefact signé (marqué non certifié dans les preuves).
        return null;
    }
}
