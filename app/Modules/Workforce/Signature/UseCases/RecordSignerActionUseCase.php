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

            return $this->applySign($request, $signer, $authMethod);
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
