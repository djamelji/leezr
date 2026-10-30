<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Crypto\DevCertificateAuthority;
use App\Core\Crypto\PersonalSignatureService;
use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureCertificate;
use App\Modules\Workforce\Signature\Models\SignatureEnrollment;
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
    public function enrollAndIssue(SignatureRequest $request, SignatureSigner $signer, string $spkiB64, string $popSignatureP1363, ?int $authUserId = null, ?string $ip = null, ?string $otp = null): SignatureCertificate
    {
        $personal = app(PersonalSignatureService::class);
        $identity = app(SignerIdentityResolver::class);
        $policy = app(SignatureAuthPolicy::class);

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

        // 4) P0 (§1/§3) — SÉPARER possession du lien ↔ AUTHENTIFICATION. Le RE-ENRÔLEMENT (changement de la
        //    clé déjà enregistrée : nouvel appareil, IndexedDB effacée, clé perdue) est une opération SENSIBLE :
        //    la seule possession du lien NE SUFFIT PAS. Un signataire INTERNE doit être AUTHENTIFIÉ avec SON
        //    compte ; un signataire EXTERNE ne peut pas changer de clé en libre-service (réinitialisation par
        //    l'émetteur requise). On ne restaure JAMAIS de clé privée ; l'ancien certificat est RÉVOQUÉ.
        $keyId = $personal->keyId($spkiB64);
        $isReEnrollment = $signer->public_key_id && ! hash_equals($signer->public_key_id, $keyId);
        if ($isReEnrollment) {
            if ($signer->isActed()) {
                throw ValidationException::withMessages(['signature' => 'Clé figée après signature — re-enrôlement impossible.']);
            }
            if ($identity->isInternal($signer)) {
                if (! $identity->authenticatedMatches($signer, $authUserId)) {
                    throw ValidationException::withMessages(['authentication' => 'Authentification de votre compte Leezr requise pour enregistrer une nouvelle clé (changement d\'appareil).']);
                }
            } else {
                // Externe : pas d'auto-substitution de clé via le lien seul (anti-substitution de signataire).
                throw ValidationException::withMessages(['authentication' => 'Le changement de clé n\'est pas autorisé en libre-service. Demandez une réinitialisation à l\'émetteur du document.']);
            }
            $this->revokePreviousCertificates($request, $signer, SignatureEnrollment::REASON_DEVICE_CHANGE);
            $signer->reenrollment_count = (int) $signer->reenrollment_count + 1;
        }

        // Niveau d'assurance RÉELLEMENT obtenu (honnête) pour cette association de clé.
        $assurance = $identity->assuranceFor($signer, $authUserId);

        // P0 (§2/§6) — le PREMIER enrôlement aussi doit respecter l'authentification exigée par la politique
        // Market/document. Pour un signataire INTERNE, le lien seul NE SUFFIT PAS : le compte attendu doit
        // être authentifié. Si la politique exige l'auth RENFORCÉE (account_mfa), une confirmation OTP
        // FRAÎCHE (step-up) est requise — réutilise le 2FA existant de Leezr, pas de MFA parallèle.
        $company = Company::withoutGlobalScopes()->find($request->company_id);
        $required = $policy->requiredAssurance($request, $signer, $company);
        if ($required === SignerIdentityResolver::ASSURANCE_MFA) {
            $user = $authUserId ? \App\Core\Models\User::find($authUserId) : null;
            $stepUpOk = $user && $otp && app(\App\Core\Auth\TwoFactorService::class)->verify($user, $otp);
            if (! $identity->authenticatedMatches($signer, $authUserId) || ! $identity->userHasMfa($authUserId) || ! $stepUpOk) {
                throw ValidationException::withMessages(['authentication' => $policy->requirementMessage($required)]);
            }
            $assurance = SignerIdentityResolver::ASSURANCE_MFA;
        }
        if (! $policy->meets($assurance, $required)) {
            throw ValidationException::withMessages(['authentication' => $policy->requirementMessage($required)]);
        }

        // 5) Enregistre la clé publique + consomme le challenge + preuve personnelle + IDENTITÉ.
        $signer->public_key_spki = $spkiB64;
        $signer->public_key_id = $keyId;
        $signer->personal_signature_alg = PersonalSignatureService::ALGORITHM;
        $signer->personal_signature = $popSignatureP1363;
        $signer->personal_signed_sha256 = hash('sha256', $payload);
        $signer->challenge_used_at = now();
        $signer->authenticated_user_id = $identity->authenticatedMatches($signer, $authUserId) ? $authUserId : null;
        $signer->identity_assurance = $assurance;
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
            'auth_method' => $assurance === SignerIdentityResolver::ASSURANCE_ACCOUNT ? 'authenticated_account' : 'token_link',
            'identity_verification' => $assurance, // account_authenticated | link_only | declared (jamais réglementaire)
            'certificate_pem' => $issued['cert_pem'],
            'metadata' => ['ca' => 'leezr-dev-internal', 'recognized_trust_chain' => false, 'eidas' => 'not_demonstrated', 'assurance' => $assurance],
        ]);

        // Historique d'enrôlement (§3) : association de clé + assurance réellement obtenue.
        SignatureEnrollment::create([
            'company_id' => $request->company_id,
            'signature_signer_id' => $signer->id,
            'public_key_id' => $keyId,
            'assurance' => $assurance,
            'authenticated_user_id' => $signer->authenticated_user_id,
            'reason' => $isReEnrollment ? SignatureEnrollment::REASON_DEVICE_CHANGE : SignatureEnrollment::REASON_INITIAL,
            'ip' => $ip,
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

    /**
     * §3 — Réinitialisation de clé par l'ÉMETTEUR (admin autorisé) : révoque la clé/cert du signataire pour
     * qu'il ré-enrôle une nouvelle clé (clé perdue, signataire externe bloqué). L'admin NE SIGNE JAMAIS à la
     * place du signataire : il libère seulement l'enrôlement. Interdit après signature.
     */
    public function adminResetKey(SignatureRequest $request, SignatureSigner $signer, int $actorId): void
    {
        if ($signer->isActed()) {
            throw ValidationException::withMessages(['signer' => 'Impossible de réinitialiser la clé : le signataire a déjà agi.']);
        }
        $this->revokePreviousCertificates($request, $signer, SignatureEnrollment::REASON_ADMIN_RESET);

        // Libère complètement l'enrôlement : le signataire posera sa PROPRE nouvelle clé au prochain parcours.
        $signer->public_key_spki = null;
        $signer->public_key_id = null;
        $signer->personal_signature = null;
        $signer->personal_signature_alg = null;
        $signer->personal_signed_sha256 = null;
        $signer->signer_certificate_pem = null;
        $signer->signer_cert_serial = null;
        $signer->signer_cert_fingerprint = null;
        $signer->authenticated_user_id = null;
        $signer->identity_assurance = 'link_only';
        $signer->reenrollment_count = (int) $signer->reenrollment_count + 1;
        $signer->save();

        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_REQUESTED,
            'signature_signer', (string) $signer->id,
            ['actorId' => $actorId, 'actorType' => 'user', 'severity' => 'warning', 'metadata' => ['action' => 'admin_key_reset']],
        );
    }

    /**
     * §5 — révoque les certificats précédents du signataire (re-enrôlement sur nouvel appareil).
     * Révocation = changement de statut (jamais d'effacement ; les anciens certs restent vérifiables).
     */
    private function revokePreviousCertificates(SignatureRequest $request, SignatureSigner $signer, string $reason): void
    {
        $ca = app(DevCertificateAuthority::class);
        $certs = SignatureCertificate::withoutGlobalScopes()
            ->where('signature_signer_id', $signer->id)
            ->where('status', SignatureCertificate::STATUS_ISSUED)->get();

        foreach ($certs as $cert) {
            $cert->status = SignatureCertificate::STATUS_REVOKED;
            $cert->revoked_at = now();
            $cert->revoked_reason = $reason;
            $cert->save();
            $ca->recordRevocation($cert->serial, $cert->fingerprint_sha256, $reason);
        }

        // Marque l'historique d'enrôlement précédent comme révoqué (traçabilité des changements de clé, §3).
        SignatureEnrollment::withoutGlobalScopes()
            ->where('signature_signer_id', $signer->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->events->record($request, SignatureEvent::TYPE_CERTIFICATE_REVOKED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['reason' => $reason, 'revoked_count' => $certs->count(), 'previous_public_key_id' => $signer->public_key_id],
        ]);

        // Libère l'ancienne clé/cert sur le signataire (la NOUVELLE sera posée par enrollAndIssue).
        $signer->signer_cert_revoked_at = now();
        $signer->public_key_id = null;
        $signer->save();
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
