<?php

namespace App\Modules\Workforce\Signature\ReadModels;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-848 (Leezr 4.2 M9) — INDEPENDENT cryptographic verification of a final signed document (§7), moved verbatim
 * from SignatureController::verify with its two helpers. Honest report : a valid seal does NOT imply the signer's
 * identity nor any eIDAS recognition. Returns [payload, http status].
 */
class SignatureVerificationReport
{
    /** @return array{0: array, 1: int} */
    public static function for(SignatureRequest $req): array
    {
        $format = $req->metadata['signature_format'] ?? ($req->metadata['crypto_seal'] ?? null ? 'cms_detached' : null);

        if (! $format || ! $req->signed_document_path) {
            return [[
                'has_seal' => false,
                'message' => 'Aucun artefact cryptographique (document antérieur à la signature native ou non finalisé).',
            ], 200];
        }

        $disk = config('documents.generated_disk', 'local');
        if (! Storage::disk($disk)->exists($req->signed_document_path)) {
            return [['has_seal' => true, 'document_available' => false, 'message' => 'Document final introuvable.'], 404];
        }
        $bytes = Storage::disk($disk)->get($req->signed_document_path);

        // Signatures PERSONNELLES (clé du signataire) — re-vérifiées INDÉPENDAMMENT, distinctes du
        // cachet organisationnel serveur. Preuve individuelle par signataire (§5, §11).
        $personalSignatures = self::verifyPersonalSignatures($req);

        // Bloc confiance commun (honnête, identique quel que soit le format).
        $trust = [
            'seal_type' => 'organizational_server_seal',
            'signer_identity_cryptographically_bound' => false,
            'eidas_advanced' => 'not_demonstrated',
            'eidas_qualified' => 'not_demonstrated',
            'note' => 'Signature/cachet cryptographique organisationnel (clé SERVEUR Leezr), vérifiable. L\'attribution au signataire repose sur le dossier de preuves (consentement + identification), pas sur une clé contrôlée par le signataire.',
        ];

        if ($format === 'pades_external') {
            // Multi-PAdES PERSONNEL : signatures EMBARQUÉES signées par la clé NAVIGATEUR de chaque
            // signataire (clé privée jamais transmise), certificats émis par l'AC interne DEV (NON reconnue).
            $report = app(\App\Core\Crypto\NativePadesSignatureService::class)->verifyPdf($bytes);
            if (empty($report['available'])) {
                return [['has_seal' => true, 'format' => $format, 'verifier_available' => false, 'message' => 'Vérificateur pdfsig indisponible.'], 200];
            }

            $externalTrust = [
                'seal_type' => 'signer_controlled_personal_signatures',
                'signer_identity_cryptographically_bound' => true, // liée à la CLÉ du signataire via son cert
                'identity_verification' => 'declared',             // identité DÉCLARÉE, pas vérifiée réglementairement
                'certificate_authority' => 'leezr-dev-internal',
                'ca_recognized' => false,                          // AC DEV NON reconnue publiquement
                'recognized_trust_chain' => $report['any_trusted_chain'] ?? false,
                'eidas_advanced' => 'not_demonstrated',
                'eidas_qualified' => 'not_demonstrated',
                'note' => 'Signatures PERSONNELLES embarquées, chacune signée par la clé privée non extractible du signataire (jamais transmise à Leezr). Certificats émis par une AC interne DEV NON reconnue : l\'identité est DÉCLARÉE, pas certifiée par une autorité de confiance publique. Non eIDAS avancé/qualifié.',
            ];

            $signerCerts = self::signerCertificates($req);

            return [[
                'has_seal' => true,
                'format' => 'pades_external',
                'embedded_signature' => true,
                'verified_by' => 'pdfsig',
                'signature_count' => $report['signature_count'],
                'all_signatures_valid' => $report['all_signatures_valid'],
                'document_intact' => $report['all_signatures_valid'],
                'overall_valid' => $report['all_signatures_valid'],
                'signatures' => $report['signatures'],
                'signer_certificates' => $signerCerts,
                'personal_signatures' => $personalSignatures,
                'trust' => $externalTrust,
                // §10 — dossier de preuves à 4 AXES (ne jamais transformer « crypto valide » en « identité certifiée »).
                'evidence' => [
                    'identity' => array_map(fn ($c) => [
                        'signer' => $c['signer_name'],
                        'assurance' => $c['identity_assurance'],          // declared|link_only|account_authenticated|account_mfa
                        'account_authenticated' => $c['account_authenticated'],
                        'auth_method' => $c['auth_method'],
                        'regulatory_identity_verified' => false,          // jamais une vérification d'identité réglementaire
                    ], $signerCerts),
                    'signature' => [
                        'personal_key' => true,
                        'proof_of_possession' => true,
                        'consent_required' => true,
                        'cryptographically_valid' => (bool) $report['all_signatures_valid'],
                        'verified_by' => 'pdfsig',
                    ],
                    'document' => [
                        'sha256' => $req->signed_document_sha256,
                        'revisions' => count($req->metadata['revisions'] ?? []),
                        'signature_count' => $report['signature_count'],
                        'intact' => (bool) $report['all_signatures_valid'],
                        'archived' => (bool) $req->signed_document_path,
                    ],
                    'trust' => [
                        'authority' => 'leezr-dev-internal',
                        'internal_chain_valid' => collect($signerCerts)->every(fn ($c) => $c['internal_chain_valid'] !== false),
                        'public_trust_established' => false,
                        'eidas_qualified_or_advanced' => 'not_demonstrated',
                    ],
                ],
            ], 200];
        }

        if ($format === 'pades_embedded') {
            // Vérification INDÉPENDANTE par pdfsig (signature EMBARQUÉE dans le PDF).
            $report = app(\App\Core\Crypto\NativePadesSignatureService::class)->verifyPdf($bytes);
            if (empty($report['available'])) {
                return [['has_seal' => true, 'format' => $format, 'verifier_available' => false, 'message' => 'Vérificateur pdfsig indisponible.', 'trust' => $trust], 200];
            }

            return [[
                'has_seal' => true,
                'format' => 'pades_embedded',
                'embedded_signature' => true,
                'verified_by' => 'pdfsig',
                'signature_count' => $report['signature_count'],
                'all_signatures_valid' => $report['all_signatures_valid'],
                'document_intact' => $report['all_signatures_valid'],   // signature embarquée valide ⇒ PDF non modifié
                'overall_valid' => $report['all_signatures_valid'],
                'signatures' => $report['signatures'],
                'certificate' => [
                    'identified' => true,
                    'recognized_trust_chain' => $report['any_trusted_chain'] ?? false,
                ],
                'personal_signatures' => $personalSignatures,
                'trust' => $trust,
            ], 200];
        }

        // CMS détaché (repli / historique récent).
        $seal = $req->metadata['crypto_seal'] ?? null;
        $svc = app(\App\Core\Crypto\CryptographicSealService::class);
        $result = $svc->verify($bytes, $seal['cms_b64'] ?? '');
        $certInfo = $svc->certificateInfo();

        return [[
            'has_seal' => true,
            'format' => 'cms_detached',
            'embedded_signature' => false,
            'verified_by' => 'openssl',
            'document_intact' => $result['document_intact'],
            'signature_valid' => $result['signature_valid'],
            'overall_valid' => $result['valid'],
            'algorithm' => $seal['algorithm'] ?? null,
            'certificate' => [
                'identified' => true,
                'recognized_trust_chain' => false,
                'self_signed' => $certInfo['self_signed'] ?? true,
                'sha256_fingerprint' => $certInfo['sha256_fingerprint'] ?? null,
            ],
            'personal_signatures' => $personalSignatures,
            'trust' => $trust,
        ], 200];
    }

    /** Certificats individuels des signataires (AC interne DEV) — détail de preuve, honnête sur la confiance. */
    private static function signerCertificates(SignatureRequest $req): array
    {
        $now = now();
        $ca = app(\App\Core\Crypto\DevCertificateAuthority::class);
        $out = [];
        foreach ($req->signers()->whereNotNull('signer_certificate_pem')->get() as $s) {
            // Validation de la CHAÎNE INTERNE (cohérence feuille→émettrice→racine) — non reconnue publiquement.
            $chainValid = null;
            try {
                $chainValid = $ca->verifyChain($s->signer_certificate_pem)['valid'] ?? null;
            } catch (\Throwable $e) {
                $chainValid = null;
            }
            $out[] = [
                'signer_name' => $s->name,
                'role' => $s->role,
                'subject_cn' => $s->name,
                'issuer' => $s->signer_cert_issuer,
                'serial' => $s->signer_cert_serial,
                'fingerprint_sha256' => $s->signer_cert_fingerprint,
                'public_key_id' => $s->public_key_id,
                'not_before' => $s->signer_cert_not_before?->toIso8601String(),
                'not_after' => $s->signer_cert_not_after?->toIso8601String(),
                'revoked' => $s->signer_cert_revoked_at !== null,
                'usable' => $s->hasUsableCertificate($now),
                'internal_chain_valid' => $chainValid,       // cohérence interne (PAS une confiance reconnue)
                // IDENTITÉ (honnête) : niveau d'assurance RÉELLEMENT obtenu, distinct de la validité crypto.
                'identity_assurance' => $s->identity_assurance ?? 'link_only',
                'account_authenticated' => $s->authenticated_user_id !== null,
                'auth_method' => $s->auth_method ?? ($s->authenticated_user_id ? 'authenticated_account' : 'token_link'),
                'reenrollment_count' => (int) ($s->reenrollment_count ?? 0),
                'identity_regulatory_verified' => false,      // jamais une vérification d'identité réglementaire
                'ca_recognized' => false,
            ];
        }

        return $out;
    }

    /** Re-vérifie INDÉPENDAMMENT les signatures personnelles stockées (preuve individuelle par signataire). */
    private static function verifyPersonalSignatures(SignatureRequest $req): array
    {
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()->find($req->generated_document_id);
        $docSha = $doc ? app(\App\Modules\Workforce\Signature\Services\SignatureDocumentService::class)->fingerprint($doc) : null;
        $svc = app(\App\Core\Crypto\PersonalSignatureService::class);
        $out = [];

        foreach ($req->signers()->whereNotNull('personal_signature')->get() as $s) {
            $valid = false;
            if ($docSha && $s->challenge && $s->public_key_spki) {
                $payload = $svc->canonicalPayload([
                    'company_id' => (int) $req->company_id,
                    'request_id' => (int) $req->id,
                    'signer_id' => (int) $s->id,
                    'signer_email' => (string) $s->email,
                    'document_sha256' => $docSha,
                    'challenge' => $s->challenge,
                ]);
                $valid = $svc->verify($s->public_key_spki, $payload, $s->personal_signature)
                    && hash_equals((string) $s->personal_signed_sha256, hash('sha256', $payload));
            }
            $out[] = [
                'signer_name' => $s->name,
                'role' => $s->role,
                'algorithm' => $s->personal_signature_alg,
                'public_key_id' => $s->public_key_id,
                'signer_controlled_key' => true,
                'verified' => $valid,
                'verified_by' => 'openssl',
            ];
        }

        return $out;
    }
}
