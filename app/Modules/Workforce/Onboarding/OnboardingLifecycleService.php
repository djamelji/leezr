<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Audit\AuditLogger;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingSession;
use Illuminate\Support\Facades\DB;

/**
 * ADR-796.1 — the ONLY place where an expiry is recorded (reads never write). Idempotent and safe under
 * concurrency : each file is re-read under a row lock and expired only if it is still writable and past
 * its expiry ; nothing is deleted except the expired link, code and sessions. Audited per file.
 * A SUBMITTED file never expires (it waits for HR) — only its candidate access lapses.
 */
final class OnboardingLifecycleService
{
    public function due(): \Illuminate\Support\Collection
    {
        return Inv::whereIn('status', Inv::CANDIDATE_WRITABLE)->where('expires_at', '<', now())->pluck('id');
    }

    public function expireDue(): int
    {
        $n = 0;
        foreach ($this->due() as $id) {
            $done = DB::transaction(function () use ($id) {
                $inv = Inv::whereKey($id)->lockForUpdate()->first();
                if (! $inv || ! in_array($inv->status, Inv::CANDIDATE_WRITABLE, true) || ! $inv->expires_at?->isPast()) {
                    return false; // déjà constaté, renouvelé ou révoqué entre-temps
                }
                $inv->sessions()->delete();
                $inv->forceFill(['status' => Inv::EXPIRED, 'expired_at' => now(), 'token_hash' => null, 'code_hash' => null, 'code_expires_at' => null])->save();
                app(AuditLogger::class)->logCompany($inv->company_id, 'onboarding.expired', 'onboarding_invitation', (string) $inv->id, ['actorType' => 'system']);

                return true;
            });
            $n += $done ? 1 : 0;
        }

        return $n;
    }

    /** Session rows past their own expiry (the cookie is useless already) — housekeeping only. */
    public function purgeExpiredSessions(): int
    {
        return OnboardingSession::where('expires_at', '<', now())->delete();
    }
}
