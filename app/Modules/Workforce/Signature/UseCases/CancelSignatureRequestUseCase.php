<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W-SIGNATURE-1 (ADR-651) — annulation d'une demande active (révoque tous les liens restants).
 */
class CancelSignatureRequestUseCase
{
    public function __construct(private readonly SignatureEventRecorder $events) {}

    public function execute(int $companyId, int $actorId, int $requestId, ?string $reason = null): SignatureRequest
    {
        return DB::transaction(function () use ($companyId, $actorId, $requestId, $reason) {
            $request = SignatureRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $request->canTransitionTo(SignatureRequest::STATUS_CANCELLED)) {
                throw ValidationException::withMessages(['status' => 'This request can no longer be cancelled.']);
            }

            $request->transitionTo(SignatureRequest::STATUS_CANCELLED);
            $request->cancelled_at = now();
            $request->cancelled_reason = $reason;
            $request->save();

            $request->signers()
                ->whereNotIn('status', [SignatureSigner::STATUS_SIGNED, SignatureSigner::STATUS_DECLINED])
                ->whereNull('token_revoked_at')
                ->update(['token_revoked_at' => now()]);

            $this->events->record($request, SignatureEvent::TYPE_CANCELLED, [
                'actorType' => SignatureEvent::ACTOR_USER,
                'actorId' => $actorId,
                'payload' => ['reason' => $reason],
            ]);

            app(\App\Core\Audit\AuditLogger::class)->logCompany(
                $companyId, \App\Core\Audit\AuditAction::SIGNATURE_CANCELLED,
                'signature_request', (string) $request->id,
                ['actorId' => $actorId, 'actorType' => 'user', 'metadata' => ['reason' => $reason]],
            );

            return $request->fresh('signers');
        });
    }
}
