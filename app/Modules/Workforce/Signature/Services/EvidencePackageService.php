<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Crypto\DevCertificateAuthority;
use App\Core\Crypto\NativePadesSignatureService;
use App\Core\Documents\GeneratedDocument;
use App\Modules\Workforce\Signature\Models\SignatureCertificate;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Support\Facades\Storage;

/**
 * W-SIGNATURE-13 (ADR-665) — DOSSIER DE PREUVES exportable, cohérent et vérifiable indépendamment.
 *
 * Contient le document signé (base64) + un manifeste (demande, signataires, certificats PUBLICS, empreintes,
 * chronologie, consentements, méthodes d'authentification, résultats de vérification, statut de confiance) +
 * un MANIFESTE D'INTÉGRITÉ (SHA-256 recalculable). Un vérifieur indépendant peut : (1) décoder le PDF et
 * recalculer son SHA-256 = `document.sha256` ; (2) exécuter pdfsig sur ce PDF ; (3) recalculer le hash du
 * manifeste (hors champ integrity) = `integrity.manifest_sha256`.
 *
 * N'INCLUT JAMAIS : clés privées, secrets, tokens d'invitation, OTP, identifiants de session. Les certificats
 * exportés sont PUBLICS (ne contiennent pas de clé privée). Minimisation des données personnelles.
 *
 * HONNÊTETÉ : distingue la VALIDITÉ CRYPTOGRAPHIQUE (octets intacts), le STATUT DE CONFIANCE ACTUEL
 * (révocation/expiration à l'instant T) et l'ÉTAT AU MOMENT DE LA SIGNATURE (horodatage APPLICATIF, non
 * qualifié). Un manifeste signé par Leezr ne confère AUCUNE qualification juridique.
 */
class EvidencePackageService
{
    public const MANIFEST_VERSION = 'leezr-signature-evidence/1.0';

    public function build(SignatureRequest $request, bool $includeDocument = true): array
    {
        $disk = config('documents.generated_disk', 'local');
        $now = now();

        // Document signé final.
        $docBytes = null;
        $docSha = $request->signed_document_sha256;
        if ($request->signed_document_path && Storage::disk($disk)->exists($request->signed_document_path)) {
            $docBytes = Storage::disk($disk)->get($request->signed_document_path);
            $docSha = hash('sha256', $docBytes);
        }

        // Vérification indépendante (pdfsig) des octets réels, si disponibles.
        $pades = app(NativePadesSignatureService::class);
        $report = ($docBytes !== null && $pades->pdfsigAvailable()) ? $pades->verifyPdf($docBytes) : ['available' => false];

        // Certificats des signataires (PUBLICS) + statut de confiance ACTUEL.
        $ca = app(DevCertificateAuthority::class);
        $signers = [];
        foreach ($request->signers()->get() as $s) {
            $cert = SignatureCertificate::withoutGlobalScopes()
                ->where('signature_signer_id', $s->id)->orderByDesc('id')->first();
            $chainValid = null;
            if ($s->signer_certificate_pem) {
                try {
                    $chainValid = $ca->verifyChain($s->signer_certificate_pem)['valid'] ?? null;
                } catch (\Throwable $e) {
                    $chainValid = null;
                }
            }
            $signers[] = [
                'name' => $s->name,
                'role' => $s->role,
                'identity_assurance' => $s->identity_assurance,          // declared|link_only|account_authenticated|account_mfa
                'auth_method' => $s->auth_method,
                'signed_at' => $s->acted_at?->toIso8601String(),          // horodatage APPLICATIF (non qualifié)
                'consent' => $s->status === 'signed',
                'certificate' => $cert ? [
                    'serial' => $cert->serial,
                    'fingerprint_sha256' => $cert->fingerprint_sha256,
                    'public_key_id' => $cert->public_key_id,
                    'subject_cn' => $cert->subject_cn,
                    'issuer_dn' => $cert->issuer_dn,
                    'not_before' => $cert->not_before?->toIso8601String(),
                    'not_after' => $cert->not_after?->toIso8601String(),
                    // STATUT ACTUEL (révocation/expiration à l'instant de l'export) — distinct de l'état au moment de la signature.
                    'status_now' => $this->certStatusNow($cert, $now),
                    'revoked_at' => $cert->revoked_at?->toIso8601String(),
                    'internal_chain_valid' => $chainValid,
                    'pem' => $cert->certificate_pem,                      // PUBLIC (aucune clé privée)
                ] : null,
            ];
        }

        // Chronologie (SANS tokens ni données sensibles).
        $timeline = $request->events()->orderBy('occurred_at')->get()->map(fn (SignatureEvent $e) => [
            'type' => $e->type,
            'actor_type' => $e->actor_type,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'verification_result' => $e->verification_result,
        ])->all();

        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'generated_at' => $now->toIso8601String(),
            'request' => [
                'id' => $request->id,
                'status' => $request->status,
                'signature_format' => $request->metadata['signature_format'] ?? null,
                'completed_at' => $request->completed_at?->toIso8601String(),
                'document_label' => $request->document?->template?->name,
                'signing_order' => $request->signing_order,
            ],
            'document' => [
                'sha256' => $docSha,
                'size' => $docBytes !== null ? strlen($docBytes) : null,
                'available' => $docBytes !== null,
            ],
            'signers' => $signers,
            'timeline' => $timeline,
            'verification' => [
                'verified_by' => 'pdfsig',
                'available' => ! empty($report['available']) || isset($report['signature_count']),
                'signature_count' => $report['signature_count'] ?? null,
                'all_signatures_valid' => $report['all_signatures_valid'] ?? null,
                'signatures' => $report['signatures'] ?? [],
                'note' => 'Validité cryptographique des octets du PDF (signatures embarquées). N\'implique pas la confiance actuelle des certificats ni une qualification eIDAS.',
            ],
            'trust' => [
                'authority' => 'leezr-dev-internal',
                'public_trust_established' => false,
                'recognized_trust_chain' => $report['any_trusted_chain'] ?? false,
                'eidas_qualified_or_advanced' => 'not_demonstrated',
                'timestamp_type' => 'application_only',   // horodatage applicatif
                'qualified_timestamp' => false,
                'legal_qualification_by_manifest' => false,
            ],
        ];

        // Manifeste d'intégrité : SHA-256 du manifeste canonique (hors champ integrity lui-même).
        $canonical = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifest['integrity'] = [
            'algorithm' => 'sha256',
            'manifest_sha256' => hash('sha256', $canonical),
            'document_sha256' => $docSha,
        ];

        if ($includeDocument && $docBytes !== null) {
            $manifest['document']['base64'] = base64_encode($docBytes);
        }

        return $manifest;
    }

    /** Statut de confiance ACTUEL d'un certificat (distinct de l'état au moment de la signature). */
    private function certStatusNow(SignatureCertificate $cert, \DateTimeInterface $now): string
    {
        if ($cert->status === SignatureCertificate::STATUS_REVOKED || $cert->revoked_at !== null) {
            return 'revoked';
        }
        if ($cert->not_after && $cert->not_after->lessThan($now)) {
            return 'expired';
        }
        if ($cert->not_before && $cert->not_before->greaterThan($now)) {
            return 'not_yet_valid';
        }

        return 'valid';
    }

    /**
     * Vérifie l'intégrité d'un manifeste exporté (recalcul indépendant). Utilisable en test/reprise.
     *
     * @return array{manifest_ok:bool, document_ok:?bool}
     */
    public function verifyManifest(array $manifest): array
    {
        $integrity = $manifest['integrity'] ?? [];
        $copy = $manifest;
        unset($copy['integrity']);
        // Le document base64 n'entre pas dans le hash du manifeste (le manifeste figeait document.sha256).
        if (isset($copy['document']['base64'])) {
            unset($copy['document']['base64']);
        }
        $canonical = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestOk = isset($integrity['manifest_sha256']) && hash_equals($integrity['manifest_sha256'], hash('sha256', $canonical));

        $documentOk = null;
        if (! empty($manifest['document']['base64']) && ! empty($manifest['document']['sha256'])) {
            $bytes = base64_decode($manifest['document']['base64'], true);
            $documentOk = $bytes !== false && hash_equals($manifest['document']['sha256'], hash('sha256', $bytes));
        }

        return ['manifest_ok' => $manifestOk, 'document_ok' => $documentOk];
    }
}
