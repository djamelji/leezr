<?php

namespace App\Modules\Workforce\Signature\UseCases;

use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Services\SignatureEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * W-SIGNATURE-1 (ADR-651) — expire les demandes actives dont la date limite est dépassée.
 * Idempotent : ne traite que les demandes non terminales réellement expirées.
 */
class ExpireSignatureRequestsUseCase
{
    public function __construct(private readonly SignatureEventRecorder $events) {}

    public function execute(?int $companyId = null): int
    {
        $query = SignatureRequest::withoutGlobalScopes()
            ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $count = 0;
        foreach ($query->get() as $request) {
            DB::transaction(function () use ($request, &$count) {
                $locked = SignatureRequest::withoutGlobalScopes()->where('id', $request->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->isActive() || ! $locked->isExpiredByTime(now())) {
                    return;
                }
                $locked->transitionTo(SignatureRequest::STATUS_EXPIRED);
                $locked->save();
                $locked->signers()
                    ->whereNotIn('status', [SignatureSigner::STATUS_SIGNED, SignatureSigner::STATUS_DECLINED])
                    ->whereNull('token_revoked_at')
                    ->update(['token_revoked_at' => now()]);
                $this->events->record($locked, SignatureEvent::TYPE_EXPIRED, ['actorType' => SignatureEvent::ACTOR_SYSTEM]);
                app(\App\Core\Audit\AuditLogger::class)->logCompany(
                    $locked->company_id, \App\Core\Audit\AuditAction::SIGNATURE_EXPIRED,
                    'signature_request', (string) $locked->id, ['actorType' => 'system', 'severity' => 'warning'],
                );
                $count++;
            });
        }

        return $count;
    }
}
