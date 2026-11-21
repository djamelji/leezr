<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Notifications\Signature\SignatureInvitationNotification;

/**
 * ADR-848 (Leezr 4.2 M9) — new single-use signing link for a signer (fresh token valid until the request expires,
 * previous one revoked) and the invitation e-mail. Was written twice, identically : RemindSignatureRequestUseCase
 * and SignatureController::resetSignerKey. The FIRST invitation (SendSignatureRequestUseCase) stays separate : it
 * goes through the signer state machine and records an event.
 */
class SignerInvitationIssuer
{
    public function __construct(private readonly SignatureNotifier $notifier) {}

    public function reissue(SignatureRequest $request, SignatureSigner $signer, ?Company $company): void
    {
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
    }
}
