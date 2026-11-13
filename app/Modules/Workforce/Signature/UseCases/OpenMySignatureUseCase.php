<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\ReadModels\MySignatureReadModel;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use App\Modules\Workforce\Signature\Services\SignatureTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-769 (M7 LOT 3) — the connected signer opens HER OWN signing ceremony from « Mes signatures ».
 *
 * Nothing is signed here : a fresh single-use link to the EXISTING signing page (`/sign/{token}`) is
 * issued for her own signer row, exactly like a reminder does (the previous e-mailed link is
 * replaced). The ceremony itself — authentication level, consent, cryptographic signature, evidence
 * — stays entirely in the existing engine. Refused for a remote provider (no in-app ceremony), an
 * inactive request, an expired one, or a signer who already acted.
 */
class OpenMySignatureUseCase
{
    public function __construct(private readonly SignatureEventRecorder $events)
    {
    }

    public function execute(Company $company, User $user, int $signerId): string
    {
        return DB::transaction(function () use ($company, $user, $signerId) {
            /** @var SignatureSigner $signer */
            $signer = MySignatureReadModel::signersOf($company, $user)->whereKey($signerId)->lockForUpdate()->firstOrFail();
            $request = SignatureRequest::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($signer->signature_request_id);
            $signer->setRelation('request', $request);

            if (! MySignatureReadModel::present($signer)['can_sign']) {
                throw ValidationException::withMessages(['signature' => 'Cette signature ne peut pas être ouverte.']);
            }

            $token = SignatureTokenService::generate();
            $signer->token_hash = $token['hash'];
            $signer->token_expires_at = $request->expires_at;
            $signer->token_revoked_at = null;
            $signer->save();

            $this->events->record($request, SignatureEvent::TYPE_INVITED, [
                'actorType' => SignatureEvent::ACTOR_USER, 'actorId' => $user->id,
                'payload' => ['self_service' => true, 'signer_id' => $signer->id],
            ]);

            return SignatureTokenService::signerUrl($token['raw']);
        });
    }
}
