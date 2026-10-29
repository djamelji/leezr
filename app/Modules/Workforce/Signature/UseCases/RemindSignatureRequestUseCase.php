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
 * W-SIGNATURE-1 (ADR-651) — relance les signataires actuellement invités (nouveau lien sécurisé).
 */
class RemindSignatureRequestUseCase
{
    public function __construct(
        private readonly SignatureEventRecorder $events,
        private readonly SignatureNotifier $notifier,
    ) {}

    public function execute(int $companyId, int $actorId, int $requestId): int
    {
        return DB::transaction(function () use ($companyId, $actorId, $requestId) {
            $request = SignatureRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_PARTIALLY_SIGNED], true)) {
                throw ValidationException::withMessages(['status' => 'Only an active request can be reminded.']);
            }

            $company = Company::withoutGlobalScopes()->find($companyId);
            $invited = $request->signers()->where('status', SignatureSigner::STATUS_INVITED)->get();
            $count = 0;
            foreach ($invited as $signer) {
                $token = SignatureTokenService::generate();
                $signer->token_hash = $token['hash'];
                $signer->token_expires_at = $request->expires_at;
                $signer->token_revoked_at = null;
                $signer->save();

                $this->notifier->sendInvitation($signer->email, new SignatureInvitationNotification(
                    signerName: $signer->name,
                    documentLabel: $request->document?->template?->name ?? 'Document RH',
                    signUrl: SignatureTokenService::signerUrl($token['raw']),
                    companyName: $company?->name,
                    expiresAt: $request->expires_at?->format('d/m/Y'),
                ));
                $count++;
            }

            $this->events->record($request, SignatureEvent::TYPE_INVITED, [
                'actorType' => SignatureEvent::ACTOR_USER, 'actorId' => $actorId,
                'payload' => ['reminder' => true, 'reminded' => $count],
            ]);

            return $count;
        });
    }
}
