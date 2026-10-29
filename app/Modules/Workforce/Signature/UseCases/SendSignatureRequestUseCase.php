<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\Services\SignatureNotifier;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use App\Modules\Workforce\Signature\Services\SignatureTokenService;
use App\Notifications\Signature\SignatureInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-1 (ADR-651) — envoie une demande (draft → pending) : crée l'enveloppe prestataire,
 * génère les liens signataires sécurisés, invite selon l'ordre (séquentiel = 1er ; parallèle = tous)
 * et projette le document source en « pending ».
 */
class SendSignatureRequestUseCase
{
    public function __construct(
        private readonly SignatureEventRecorder $events,
        private readonly SignatureNotifier $notifier,
        private readonly SignatureSettingsService $settings,
    ) {}

    public function execute(int $companyId, int $actorId, int $requestId): SignatureRequest
    {
        $request = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('signers')
            ->findOrFail($requestId);

        if ($request->status !== SignatureRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft request can be sent.']);
        }

        $adapter = SignatureProviderManager::adapterFor($request->provider);
        if ($adapter === null) {
            throw ValidationException::withMessages(['provider' => 'Signature provider unavailable.']);
        }

        $cfg = $this->settings->resolve($companyId);
        $company = Company::withoutGlobalScopes()->find($companyId);
        $docLabel = $this->documentLabel($request);

        return DB::transaction(function () use ($request, $actorId, $adapter, $cfg, $company, $docLabel) {
            $signers = $request->signers()->orderBy('order')->get();

            // 1. Enveloppe prestataire.
            $envelope = $adapter->createEnvelope($request, $signers->all());

            // Référence prestataire par signataire (corrélation des webhooks).
            $signerRefs = $envelope['signer_references'] ?? [];
            foreach ($signers as $signer) {
                if (isset($signerRefs[$signer->id])) {
                    $signer->evidence_reference = $signerRefs[$signer->id];
                    $signer->save();
                }
            }

            // 2. Transition draft → pending.
            $request->transitionTo(SignatureRequest::STATUS_PENDING);
            $request->provider_reference = $envelope['provider_reference'] ?? null;
            $request->sent_at = now();
            $request->save();

            // 3. Qui inviter maintenant ?
            $toInvite = $request->signing_order === SignatureRequest::ORDER_PARALLEL
                ? $signers->all()
                : [$signers->first()];

            foreach ($toInvite as $signer) {
                if (! $signer || $signer->status !== SignatureSigner::STATUS_PENDING) {
                    continue;
                }
                $this->inviteSigner($request, $signer, $company, $docLabel, $cfg['notify_enabled']);
            }

            // 4. Projection du document source → pending (si transition permise).
            $this->projectDocument($request, GeneratedDocument::SIGNATURE_PENDING);

            $this->events->record($request, SignatureEvent::TYPE_SENT, [
                'actorType' => SignatureEvent::ACTOR_USER,
                'actorId' => $actorId,
                'payload' => ['invited' => count($toInvite), 'order' => $request->signing_order],
            ]);

            app(\App\Core\Audit\AuditLogger::class)->logCompany(
                $request->company_id,
                \App\Core\Audit\AuditAction::SIGNATURE_SENT,
                'signature_request',
                (string) $request->id,
                ['actorId' => $actorId, 'actorType' => 'user'],
            );

            return $request->fresh('signers');
        });
    }

    private function inviteSigner(SignatureRequest $request, SignatureSigner $signer, ?Company $company, string $docLabel, bool $notify): void
    {
        $token = SignatureTokenService::generate();
        $signer->token_hash = $token['hash'];
        $signer->token_expires_at = $request->expires_at;
        $signer->token_revoked_at = null;
        $signer->invited_at = now();
        $signer->transitionTo(SignatureSigner::STATUS_INVITED);
        $signer->save();

        $this->events->record($request, SignatureEvent::TYPE_INVITED, [
            'signer' => $signer,
            'actorType' => SignatureEvent::ACTOR_SYSTEM,
            'payload' => ['role' => $signer->role, 'order' => $signer->order],
        ]);

        if ($notify) {
            $this->notifier->sendInvitation($signer->email, new SignatureInvitationNotification(
                signerName: $signer->name,
                documentLabel: $docLabel,
                signUrl: SignatureTokenService::signerUrl($token['raw']),
                companyName: $company?->name,
                expiresAt: $request->expires_at?->format('d/m/Y'),
            ));
        }
    }

    private function projectDocument(SignatureRequest $request, string $target): void
    {
        $doc = GeneratedDocument::withoutGlobalScopes()->find($request->generated_document_id);
        if ($doc && $doc->canTransitionSignatureTo($target)) {
            $doc->transitionSignatureTo($target);
            $doc->signature_provider = $request->provider;
            $doc->save();
        }
    }

    private function documentLabel(SignatureRequest $request): string
    {
        $doc = GeneratedDocument::withoutGlobalScopes()->with('template')->find($request->generated_document_id);

        return $doc?->template?->name ?? 'Document RH';
    }
}
