<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\Services\SignatureNotifier;
use App\Modules\Workforce\Signature\Services\SignatureTokenService;
use App\Notifications\Signature\SignatureInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-1 (ADR-651) — action d'un signataire via son lien sécurisé (signer / refuser).
 *
 * Protège contre : token invalide/expiré/révoqué, rejeu (signataire déjà agi), action hors séquence,
 * double soumission, demande non active, accès cross-tenant (résolution par hash de token opaque).
 * Un refus rend la demande terminale (declined) : pas de transformation refus → succès sans nouvelle
 * procédure.
 */
class RecordSignerActionUseCase
{
    public function __construct(
        private readonly SignatureEventRecorder $events,
        private readonly SignatureNotifier $notifier,
    ) {}

    /** Résout le signataire ciblé par un token brut (constant-time), sans contexte tenant. */
    public function resolveSigner(string $rawToken): ?SignatureSigner
    {
        $hash = SignatureTokenService::hash($rawToken);
        $signer = SignatureSigner::withoutGlobalScopes()->where('token_hash', $hash)->first();
        if (! $signer) {
            return null;
        }
        // Comparaison constante (le where ci-dessus matche déjà le hash, mais on reste explicite).
        return SignatureTokenService::matches($rawToken, $signer->token_hash) ? $signer : null;
    }

    public function sign(string $rawToken, array $context = []): SignatureRequest
    {
        return $this->act($rawToken, SignatureSigner::STATUS_SIGNED, $context);
    }

    public function decline(string $rawToken, ?string $reason = null, array $context = []): SignatureRequest
    {
        return $this->act($rawToken, SignatureSigner::STATUS_DECLINED, array_merge($context, ['reason' => $reason]));
    }

    private function act(string $rawToken, string $action, array $context): SignatureRequest
    {
        // Expiration paresseuse AVANT la transaction d'action : expirer dans sa propre transaction,
        // sinon le throw ci-dessous annulerait (rollback) la transition d'expiration.
        $pre = $this->resolveSigner($rawToken);
        if ($pre) {
            $preReq = SignatureRequest::withoutGlobalScopes()->find($pre->signature_request_id);
            if ($preReq && $preReq->isActive() && $preReq->isExpiredByTime(now())) {
                DB::transaction(fn () => $this->expire(
                    SignatureRequest::withoutGlobalScopes()->where('id', $preReq->id)->lockForUpdate()->first(),
                ));
                throw ValidationException::withMessages(['token' => 'This signature request has expired.']);
            }
        }

        return DB::transaction(function () use ($rawToken, $action, $context) {
            $signer = $this->resolveSigner($rawToken);
            if (! $signer) {
                throw ValidationException::withMessages(['token' => 'Invalid signature link.']);
            }

            // Verrou de ligne pour éviter double soumission concurrente.
            $request = SignatureRequest::withoutGlobalScopes()
                ->where('id', $signer->signature_request_id)
                ->lockForUpdate()
                ->firstOrFail();
            $signer = SignatureSigner::withoutGlobalScopes()->where('id', $signer->id)->lockForUpdate()->firstOrFail();

            if (! $request->isActive()) {
                throw ValidationException::withMessages(['token' => 'This signature request is no longer open.']);
            }
            if (! $signer->tokenIsUsable(now())) {
                throw ValidationException::withMessages(['token' => 'This signature link is no longer usable.']);
            }
            if ($signer->isActed()) {
                throw ValidationException::withMessages(['token' => 'This signer has already acted.']);
            }

            // Séquence : en mode séquentiel, aucun signataire d'ordre inférieur ne doit rester non agi.
            if ($request->signing_order === SignatureRequest::ORDER_SEQUENTIAL) {
                $earlierPending = $request->signers()
                    ->where('order', '<', $signer->order)
                    ->whereNotIn('status', [SignatureSigner::STATUS_SIGNED])
                    ->exists();
                if ($earlierPending) {
                    throw ValidationException::withMessages(['token' => 'It is not yet this signer\'s turn.']);
                }
            }

            $authMethod = $context['authMethod'] ?? 'email_link';

            if ($action === SignatureSigner::STATUS_DECLINED) {
                return $this->applyDecline($request, $signer, $context['reason'] ?? null, $authMethod);
            }

            // W-SIGNATURE-7 — PAdES externe : injection incrémentale de la signature du navigateur dans la
            // révision courante (préparation faite en amont via prepareExternal). La preuve de possession
            // a été vérifiée à la préparation ; la signature PAdES est re-vérifiée contre la clé du cert
            // à l'injection. Injection sérialisée par le verrou de ligne ci-dessus (§9).
            // H1 (ADR-660) : une demande pades_external EXIGE une signature PAdES. Sans elle, on NE tombe
            // PAS dans le lien-seul (qui brûlerait le token + journaliserait un « signé » mensonger +
            // rendrait la demande incomplétable). Échec contrôlé, token préservé.
            $requiredFormat = $request->metadata['required_format'] ?? 'pades_embedded';
            if ($requiredFormat === 'pades_external') {
                if (empty($context['padesSignature'])) {
                    throw ValidationException::withMessages(['signature' => 'Signature PAdES requise pour cette demande.']);
                }
                app(\App\Modules\Workforce\Signature\Services\ExternalPadesCoordinator::class)
                    ->apply($request, $signer, $context['padesSignature']);

                return $this->applySign($request, $signer, 'webcrypto_pades_external');
            }

            // Signature PERSONNELLE (clé du signataire) : vérifiée + preuve stockée AVANT la transition.
            // Si fournie et INVALIDE → rejet (pas de transition réussie). Si absente → lien sécurisé seul.
            $personalMethod = $this->verifyAndStorePersonalSignature($request, $signer, $context);
            if ($personalMethod !== null) {
                $authMethod = $personalMethod;
            }

            return $this->applySign($request, $signer, $authMethod);
        });
    }

    /**
     * W-SIGNATURE-7 — Phase 1 du PAdES externe : vérifie la preuve de possession, émet le certificat
     * individuel du signataire (AC interne DEV) et prépare les octets `to_sign` sur la révision courante.
     * Sérialisée/verrouillée comme une action normale. Ne modifie pas le statut du signataire.
     *
     * @param  array{publicKey?:string, pop?:string}  $context
     * @return array{to_sign_b64:string, prep_expires_at:string, base_sha256:string}
     */
    public function prepareExternal(string $rawToken, array $context): array
    {
        return DB::transaction(function () use ($rawToken, $context) {
            $signer = $this->resolveSigner($rawToken);
            if (! $signer) {
                throw ValidationException::withMessages(['token' => 'Invalid signature link.']);
            }
            $request = SignatureRequest::withoutGlobalScopes()->where('id', $signer->signature_request_id)->lockForUpdate()->firstOrFail();
            $signer = SignatureSigner::withoutGlobalScopes()->where('id', $signer->id)->lockForUpdate()->firstOrFail();

            if (! $request->isActive()) {
                throw ValidationException::withMessages(['token' => 'This signature request is no longer open.']);
            }
            if (! $signer->tokenIsUsable(now())) {
                throw ValidationException::withMessages(['token' => 'This signature link is no longer usable.']);
            }
            if ($signer->isActed()) {
                throw ValidationException::withMessages(['token' => 'This signer has already acted.']);
            }
            if (($request->metadata['required_format'] ?? null) !== 'pades_external') {
                throw ValidationException::withMessages(['token' => 'This request does not use external PAdES signing.']);
            }
            // Séquence : aucun signataire d'ordre inférieur non agi (mode séquentiel).
            if ($request->signing_order === SignatureRequest::ORDER_SEQUENTIAL) {
                $earlierPending = $request->signers()
                    ->where('order', '<', $signer->order)
                    ->whereNotIn('status', [SignatureSigner::STATUS_SIGNED])
                    ->exists();
                if ($earlierPending) {
                    throw ValidationException::withMessages(['token' => 'It is not yet this signer\'s turn.']);
                }
            }

            $spki = $context['publicKey'] ?? null;
            $pop = $context['pop'] ?? null;
            if (! $spki || ! $pop) {
                throw ValidationException::withMessages(['signature' => 'Preuve de possession requise.']);
            }

            // Vérifie la preuve de possession + émet/charge le certificat individuel. Transmet l'identité
            // RÉELLEMENT authentifiée (si présente) : requise pour tout re-enrôlement de signataire interne.
            app(\App\Modules\Workforce\Signature\Services\SignerEnrollmentService::class)
                ->enrollAndIssue($request, $signer, $spki, $pop, $context['authUserId'] ?? null, $context['ip'] ?? null);
            $signer->refresh();

            // Prépare les octets exacts à signer sur la révision courante.
            return app(\App\Modules\Workforce\Signature\Services\ExternalPadesCoordinator::class)
                ->prepare($request, $signer);
        });
    }

    /**
     * Signature pilotée par une AUTORITÉ SERVEUR vérifiée (webhook prestataire authentifié), sans token.
     * La machine à états rejette toute action illégale (désordre, demande terminée, signataire déjà agi).
     */
    public function signByServerAuthority(SignatureSigner $signer, string $authMethod): SignatureRequest
    {
        return DB::transaction(function () use ($signer, $authMethod) {
            $request = SignatureRequest::withoutGlobalScopes()->where('id', $signer->signature_request_id)->lockForUpdate()->firstOrFail();
            $signer = SignatureSigner::withoutGlobalScopes()->where('id', $signer->id)->lockForUpdate()->firstOrFail();
            if (! $request->isActive() || $signer->isActed()) {
                return $request->fresh('signers');
            }

            return $this->applySign($request, $signer, $authMethod);
        });
    }

    public function declineByServerAuthority(SignatureSigner $signer, ?string $reason, string $authMethod): SignatureRequest
    {
        return DB::transaction(function () use ($signer, $reason, $authMethod) {
            $request = SignatureRequest::withoutGlobalScopes()->where('id', $signer->signature_request_id)->lockForUpdate()->firstOrFail();
            $signer = SignatureSigner::withoutGlobalScopes()->where('id', $signer->id)->lockForUpdate()->firstOrFail();
            if (! $request->isActive() || $signer->isActed()) {
                return $request->fresh('signers');
            }

            return $this->applyDecline($request, $signer, $reason, $authMethod);
        });
    }

    /**
     * Vérifie la signature PERSONNELLE du signataire (ECDSA P-256 WebCrypto) et stocke la preuve.
     * @return ?string la méthode d'auth si une signature personnelle valide a été fournie ; null sinon.
     * @throws ValidationException si une signature personnelle est fournie mais invalide.
     */
    private function verifyAndStorePersonalSignature(SignatureRequest $request, SignatureSigner $signer, array $context): ?string
    {
        $pub = $context['personalPublicKey'] ?? null;
        $sig = $context['personalSignature'] ?? null;
        if (! $pub || ! $sig) {
            return null;
        }

        // Challenge à usage unique : présent, non expiré, non consommé.
        if (! $signer->challenge || ! $signer->challenge_expires_at || $signer->challenge_expires_at->isPast() || $signer->challenge_used_at) {
            throw ValidationException::withMessages(['signature' => 'Challenge invalide, expiré ou déjà utilisé.']);
        }

        // Empreinte du document EXACT (recalculée serveur) — lie la signature au document précis.
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()->find($request->generated_document_id);
        $docSha = $doc ? app(\App\Modules\Workforce\Signature\Services\SignatureDocumentService::class)->fingerprint($doc) : null;
        if (! $docSha) {
            throw ValidationException::withMessages(['signature' => 'Document source introuvable.']);
        }

        $svc = app(\App\Core\Crypto\PersonalSignatureService::class);
        $payload = $svc->canonicalPayload([
            'company_id' => (int) $request->company_id,
            'request_id' => (int) $request->id,
            'signer_id' => (int) $signer->id,
            'signer_email' => (string) $signer->email,
            'document_sha256' => $docSha,
            'challenge' => $signer->challenge,
        ]);

        if (! $svc->verify($pub, $payload, $sig)) {
            throw ValidationException::withMessages(['signature' => 'Signature personnelle invalide.']);
        }

        // Si une clé était déjà enregistrée pour ce signataire, elle DOIT correspondre (pas de substitution).
        $keyId = $svc->keyId($pub);
        if ($signer->public_key_id && ! hash_equals($signer->public_key_id, $keyId)) {
            throw ValidationException::withMessages(['signature' => 'Clé de signature différente de la clé enregistrée.']);
        }

        $signer->public_key_spki = $pub;
        $signer->public_key_id = $keyId;
        $signer->personal_signature_alg = \App\Core\Crypto\PersonalSignatureService::ALGORITHM;
        $signer->personal_signature = $sig;
        $signer->personal_signed_sha256 = hash('sha256', $payload);
        $signer->challenge_used_at = now();
        $signer->save();

        $this->events->record($request, SignatureEvent::TYPE_SIGNED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SIGNER,
            'actorId' => $signer->id,
            'payload' => [
                'personal_signature' => true,
                'algorithm' => \App\Core\Crypto\PersonalSignatureService::ALGORITHM,
                'public_key_id' => $keyId,
                'document_sha256' => $docSha,
                'verified_by' => 'openssl',
                'signature_type' => 'signer_controlled_key',
            ],
        ]);

        return 'webcrypto_ecdsa_p256';
    }

    private function applySign(SignatureRequest $request, SignatureSigner $signer, string $authMethod): SignatureRequest
    {
        $signer->transitionTo(SignatureSigner::STATUS_SIGNED);
        $signer->auth_method = $authMethod;
        $signer->acted_at = now();
        $signer->token_revoked_at = now(); // lien à usage unique
        $signer->save();

        $this->events->record($request, SignatureEvent::TYPE_SIGNED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SIGNER,
            'actorId' => $signer->id,
            'payload' => ['auth_method' => $authMethod, 'role' => $signer->role, 'order' => $signer->order, 'certified' => false],
        ]);

        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_SIGNED,
            'signature_request', (string) $request->id,
            ['actorType' => 'signer', 'metadata' => ['signer_id' => $signer->id]],
        );

        $remaining = $request->signers()->whereNotIn('status', [SignatureSigner::STATUS_SIGNED])->count();

        if ($remaining === 0) {
            // Tous ont signé → finalisation (vérification empreinte + preuves + archivage).
            app(FinalizeSignatureRequestUseCase::class)->execute($request);

            return $request->fresh('signers');
        }

        // Il reste des signataires : partially_signed + inviter le suivant si séquentiel.
        if ($request->status !== SignatureRequest::STATUS_PARTIALLY_SIGNED) {
            $request->transitionTo(SignatureRequest::STATUS_PARTIALLY_SIGNED);
            $request->save();
        }

        if ($request->signing_order === SignatureRequest::ORDER_SEQUENTIAL) {
            $next = $request->signers()
                ->where('status', SignatureSigner::STATUS_PENDING)
                ->orderBy('order')->first();
            if ($next) {
                $this->inviteNext($request, $next);
            }
        }

        return $request->fresh('signers');
    }

    private function applyDecline(SignatureRequest $request, SignatureSigner $signer, ?string $reason, string $authMethod): SignatureRequest
    {
        $signer->transitionTo(SignatureSigner::STATUS_DECLINED);
        $signer->auth_method = $authMethod;
        $signer->acted_at = now();
        $signer->decline_reason = $reason;
        $signer->token_revoked_at = now();
        $signer->save();

        $this->events->record($request, SignatureEvent::TYPE_DECLINED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SIGNER,
            'actorId' => $signer->id,
            'payload' => ['reason' => $reason, 'role' => $signer->role],
        ]);

        // Un refus rend la demande terminale ; on révoque les liens restants.
        $request->transitionTo(SignatureRequest::STATUS_DECLINED);
        $request->save();
        $this->revokeRemainingTokens($request);

        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_DECLINED,
            'signature_request', (string) $request->id,
            ['actorType' => 'signer', 'severity' => 'warning', 'metadata' => ['signer_id' => $signer->id]],
        );

        return $request->fresh('signers');
    }

    private function inviteNext(SignatureRequest $request, SignatureSigner $signer): void
    {
        $token = SignatureTokenService::generate();
        $signer->token_hash = $token['hash'];
        $signer->token_expires_at = $request->expires_at;
        $signer->invited_at = now();
        $signer->transitionTo(SignatureSigner::STATUS_INVITED);
        $signer->save();

        $this->events->record($request, SignatureEvent::TYPE_INVITED, [
            'signer' => $signer, 'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['role' => $signer->role, 'order' => $signer->order],
        ]);

        $company = Company::withoutGlobalScopes()->find($request->company_id);
        $this->notifier->sendInvitation($signer->email, new SignatureInvitationNotification(
            signerName: $signer->name,
            documentLabel: $request->document?->template?->name ?? 'Document RH',
            signUrl: SignatureTokenService::signerUrl($token['raw']),
            companyName: $company?->name,
            expiresAt: $request->expires_at?->format('d/m/Y'),
        ));
    }

    private function revokeRemainingTokens(SignatureRequest $request): void
    {
        $request->signers()
            ->whereNotIn('status', [SignatureSigner::STATUS_SIGNED, SignatureSigner::STATUS_DECLINED])
            ->whereNull('token_revoked_at')
            ->update(['token_revoked_at' => now()]);
    }

    private function expire(SignatureRequest $request): void
    {
        $request->transitionTo(SignatureRequest::STATUS_EXPIRED);
        $request->save();
        $this->revokeRemainingTokens($request);
        $this->events->record($request, SignatureEvent::TYPE_EXPIRED, ['actorType' => SignatureEvent::ACTOR_SYSTEM]);
        app(\App\Core\Audit\AuditLogger::class)->logCompany(
            $request->company_id, \App\Core\Audit\AuditAction::SIGNATURE_EXPIRED,
            'signature_request', (string) $request->id, ['actorType' => 'system', 'severity' => 'warning'],
        );
    }
}
