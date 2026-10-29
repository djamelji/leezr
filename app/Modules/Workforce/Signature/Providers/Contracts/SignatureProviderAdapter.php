<?php

namespace App\Modules\Workforce\Signature\Providers\Contracts;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\Results\WebhookOutcome;

/**
 * W-SIGNATURE-1 (ADR-651) — abstraction HORIZONTALE d'un prestataire de signature.
 *
 * Objectif : pouvoir brancher à terme Yousign/DocuSign/etc. selon les Markets, sans jamais coder
 * un fournisseur dans les contrôleurs. Chaque étape est une responsabilité distincte :
 * création d'enveloppe, préparation des signataires, liens sécurisés, invitations, réception
 * d'événements, vérification des preuves, récupération du document final.
 *
 * INVARIANT DE CONFORMITÉ : un prestataire qui ne sait pas produire la preuve d'un niveau ne doit
 * PAS annoncer ce niveau (voir supportsLevel/capabilities). Ne jamais présenter une signature comme
 * conforme à une norme sans vérification démontrable (isCertified()).
 */
interface SignatureProviderAdapter
{
    /** Clé technique du prestataire (ex: 'internal', 'yousign'). */
    public function key(): string;

    /**
     * Le prestataire produit-il des preuves juridiquement certifiées (signature avancée/qualifiée réelle) ?
     * L'adaptateur interne de test renvoie TOUJOURS false.
     */
    public function isCertified(): bool;

    /** Réservé aux environnements de développement/test (bloqué en PROD). */
    public function isDevOnly(): bool;

    /**
     * Niveaux de signature RÉELLEMENT supportés pour un Market donné.
     * @return string[] sous-ensemble de SignatureRequest::LEVELS
     */
    public function supportedLevels(string $marketKey): array;

    public function supportsLevel(string $marketKey, string $level): bool;

    /**
     * Crée l'enveloppe côté prestataire et prépare les signataires.
     * @return array{provider_reference: string, signer_references: array<int,string>, certified: bool}
     */
    public function createEnvelope(SignatureRequest $request, array $signers): array;

    /**
     * Vérifie la signature cryptographique d'un webhook sur le corps BRUT.
     * DOIT lever une exception si la vérification échoue (fail-closed).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): void;

    /** Traite un événement webhook déjà vérifié + idempotent. */
    public function handleWebhookEvent(array $payload, array $headers): WebhookOutcome;

    /**
     * Vérifie les preuves du prestataire pour une demande finalisée.
     * @return bool true si les preuves sont valides et correspondent à la demande.
     */
    public function verifyEvidence(SignatureRequest $request): bool;

    /**
     * Récupère les octets du document final signé auprès du prestataire, ou null si indisponible.
     */
    public function fetchFinalDocument(SignatureRequest $request): ?string;
}
