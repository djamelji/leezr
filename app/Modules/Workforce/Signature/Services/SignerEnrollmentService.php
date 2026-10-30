<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Crypto\DevCertificateAuthority;
use App\Core\Crypto\PersonalSignatureService;
use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureCertificate;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-7 (ADR-659) — enrôlement de la clé personnelle + émission du certificat individuel.
 *
 * Le signataire PROUVE la possession de sa clé privée WebCrypto non extractible en signant une charge
 * CANONIQUE liée au document + à la demande + à un CHALLENGE à usage unique (preuve de possession, §4).
 * Après vérification de cette preuve, l'AC interne DEV émet un certificat X.509 liant la clé publique
 * RÉELLE à l'identité DÉCLARÉE du signataire. La clé privée n'est JAMAIS transmise ni reconstituée.
 *
 * Garde-fous :
 *   - l'identité (CN/role/organisation) provient du SERVEUR (signer + company), jamais du frontend ;
 *   - anti-substitution : une clé déjà enregistrée pour ce signataire doit correspondre ;
 *   - le challenge est consommé (anti-rejeu) ;
 *   - idempotence : une clé déjà enrôlée avec un certificat utilisable n'est pas réémise.
 */
class SignerEnrollmentService
{
    public function __construct(
        private readonly SignatureDocumentService $documents,
        private readonly SignatureEventRecorder $events,
    ) {}

    /**
     * Vérifie la preuve de possession et garantit un certificat individuel utilisable pour le signataire.
     *
     * @return SignatureCertificate le certificat actif (réutilisé ou nouvellement émis)
     *
     * @throws ValidationException si la preuve de possession est invalide / le challenge inutilisable
     */
    public function enrollAndIssue(SignatureRequest $request, SignatureSigner $signer, string $spkiB64, string $popSignatureP1363): SignatureCertificate
    {
        $personal = app(PersonalSignatureService::class);

        // 1) Challenge à usage unique : présent, non expiré, non consommé.
        if (! $signer->challenge || ! $signer->challenge_expires_at || $signer->challenge_expires_at->isPast() || $signer->challenge_used_at) {
            throw ValidationException::withMessages(['signature' => 'Challenge invalide, expiré ou déjà utilisé.']);
        }

        // 2) Empreinte du document EXACT (recalculée serveur) — lie la preuve au document précis.
        $doc = GeneratedDocument::withoutGlobalScopes()->find($request->generated_document_id);
        $docSha = $doc ? $this->documents->fingerprint($doc) : null;
        if (! $docSha) {
            throw ValidationException::withMessages(['signature' => 'Document source introuvable.']);
        }

        // 3) Vérification de la PREUVE DE POSSESSION (signature canonique challenge-liée).
        $payload = $personal->canonicalPayload([
            'company_id' => (int) $request->company_id,
            'request_id' => (int) $request->id,
            'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email,
            'document_sha256' => $docSha,
            'challenge' => $signer->challenge,
        ]);
        if (! $personal->verify($spkiB64, $payload, $popSignatureP1363)) {
            throw ValidationException::withMessages(['signature' => 'Preuve de possession invalide.']);
        }

        // 4) Anti-substitution : une clé déjà enregistrée doit correspondre.
        $keyId = $personal->keyId($spkiB64);
        if ($signer->public_key_id && ! hash_equals($signer->public_key_id, $keyId)) {
            throw ValidationException::withMessages(['signature' => 'Clé de signature différente de la clé enregistrée.']);
        }

        // 5) Enregistre la clé publique + consomme le challenge + preuve personnelle (comme W-SIGNATURE-4).
        $signer->public_key_spki = $spkiB64;
        $signer->public_key_id = $keyId;
        $signer->personal_signature_alg = PersonalSignatureService::ALGORITHM;
        $signer->personal_signature = $popSignatureP1363;
        $signer->personal_signed_sha256 = hash('sha256', $payload);
        $signer->challenge_used_at = now();
        $signer->save();

        // 6) Idempotence : certificat utilisable déjà lié à cette clé → réutiliser.
        $existing = SignatureCertificate::withoutGlobalScopes()
            ->where('signature_signer_id', $signer->id)
            ->where('public_key_id', $keyId)
            ->where('status', SignatureCertificate::STATUS_ISSUED)
            ->latest('id')->first();
        if ($existing && $existing->isUsable(now()) && $signer->hasUsableCertificate(now())) {
            return $existing;
        }

        // 7) Émission du certificat individuel via l'AC interne DEV (identité SERVEUR, pas frontend).
        $ca = app(DevCertificateAuthority::class);
        if (! $ca->issuanceEnabled()) {
            throw ValidationException::withMessages(['signature' => "L'émission de certificats est désactivée dans cet environnement."]);
        }

        $company = Company::withoutGlobalScopes()->find($request->company_id);
        $issued = $ca->issueSignerCertificate(
            $spkiB64,
            [
                'cn' => $signer->name,
                'ou' => $this->roleLabel($signer->role),
                'o' => $company?->name ? $company->name . ' (Leezr DEV, non-trusted)' : 'Leezr DEV Signer (non-trusted test)',
                'c' => 'FR',
            ],
            (int) config('signature.dev_ca.validity_days', 365),
        );

        // 8) Persiste le certificat ACTIF sur le signataire + trace d'historique.
        $signer->signer_certificate_pem = $issued['cert_pem'];
        $signer->signer_cert_serial = $issued['serial_hex'];
        $signer->signer_cert_fingerprint = $issued['fingerprint_sha256'];
        $signer->signer_cert_issuer = $issued['issuer_dn'];
        $signer->signer_cert_not_before = date('Y-m-d H:i:s', $issued['not_before']);
        $signer->signer_cert_not_after = date('Y-m-d H:i:s', $issued['not_after']);
        $signer->signer_cert_revoked_at = null;
        $signer->save();

        $certificate = SignatureCertificate::create([
            'company_id' => $request->company_id,
            'signature_request_id' => $request->id,
            'signature_signer_id' => $signer->id,
            'serial' => $issued['serial_hex'],
            'fingerprint_sha256' => $issued['fingerprint_sha256'],
            'public_key_id' => $keyId,
            'subject_cn' => $signer->name,
            'issuer_dn' => $issued['issuer_dn'],
            'not_before' => date('Y-m-d H:i:s', $issued['not_before']),
            'not_after' => date('Y-m-d H:i:s', $issued['not_after']),
            'status' => SignatureCertificate::STATUS_ISSUED,
            'auth_method' => 'token_link',
            'identity_verification' => 'declared', // compte + lien email ≠ vérification réglementaire
            'certificate_pem' => $issued['cert_pem'],
            'metadata' => ['ca' => 'leezr-dev-internal', 'recognized_trust_chain' => false, 'eidas' => 'not_demonstrated'],
        ]);

        $this->events->record($request, SignatureEvent::TYPE_CERTIFICATE_ISSUED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => [
                'serial' => $issued['serial_hex'],
                'fingerprint_sha256' => $issued['fingerprint_sha256'],
                'public_key_id' => $keyId,
                'issuer' => $issued['issuer_dn'],
                'identity_verification' => 'declared',
                'ca_trusted' => false,
            ],
        ]);

        return $certificate;
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            SignatureSigner::ROLE_COMPANY_REPRESENTATIVE => 'Company Representative',
            SignatureSigner::ROLE_EMPLOYEE => 'Employee',
            default => 'External Signer',
        };
    }
}
