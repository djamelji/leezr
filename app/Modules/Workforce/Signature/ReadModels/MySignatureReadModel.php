<?php

namespace App\Modules\Workforce\Signature\ReadModels;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use App\Modules\Workforce\Signature\Providers\Contracts\SealsDocumentRemotely;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-769 (M7 LOT 3) — « Mes signatures » : the signature requests in which the CONNECTED PERSON is
 * a signer, in the current company. Ownership only (the signer row designates her account, or the
 * HR record explicitly linked to her account) — no `workforce_signature.*` permission is consulted,
 * and an administrator sees here nothing more than her own signatures.
 *
 * One personal state per row, derived from the engine's real states :
 *   to_sign | in_progress (I signed, others pending) | signed | declined | expired | cancelled | failed
 */
class MySignatureReadModel
{
    /** Signer rows that designate this person in this company. */
    public static function signersOf(Company $company, User $user): Builder
    {
        $employeeIds = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->pluck('id')->all();

        return SignatureSigner::withoutGlobalScopes()->where('company_id', $company->id)
            ->where(function ($q) use ($user, $employeeIds) {
                $q->where(fn ($w) => $w->where('party_type', 'user')->where('party_id', $user->id));
                if ($employeeIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('party_type', 'employee')->whereIn('party_id', $employeeIds));
                }
            });
    }

    public static function toSignCount(Company $company, User $user): int
    {
        return self::signersOf($company, $user)->where('status', SignatureSigner::STATUS_INVITED)
            ->whereHas('request', fn ($r) => $r->withoutGlobalScopes()
                ->whereIn('status', [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_PARTIALLY_SIGNED])
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->count();
    }

    public static function list(Company $company, User $user): array
    {
        $signers = self::signersOf($company, $user)
            ->with(['request' => fn ($r) => $r->withoutGlobalScopes()->with('document.template:id,name')])
            ->latest('id')->limit(200)->get();

        return $signers->filter(fn (SignatureSigner $s) => $s->request !== null && $s->request->status !== SignatureRequest::STATUS_DRAFT)
            ->map(fn (SignatureSigner $s) => self::present($s))->values()->all();
    }

    public static function present(SignatureSigner $signer): array
    {
        $request = $signer->request;
        $state = self::state($signer, $request);
        $remote = self::isRemote($request);

        return [
            'id' => $signer->id,
            'request_id' => $request->id,
            'document_label' => $request->document?->template?->name ?? 'Document',
            'role' => $signer->role,
            'state' => $state,
            'sent_at' => $request->sent_at?->toIso8601String(),
            'acted_at' => $signer->acted_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            // The signing ceremony of a remote provider happens at the provider : Leezr offers no
            // in-app action for it (the provider's own invitation is the only way in).
            'handled_by_provider' => $remote,
            'can_sign' => $state === 'to_sign' && ! $remote,
            'can_download' => $request->status === SignatureRequest::STATUS_COMPLETED && $request->signed_document_path !== null,
        ];
    }

    private static function state(SignatureSigner $signer, SignatureRequest $request): string
    {
        if ($signer->status === SignatureSigner::STATUS_DECLINED || $request->status === SignatureRequest::STATUS_DECLINED) {
            return 'declined';
        }
        if ($request->status === SignatureRequest::STATUS_CANCELLED) {
            return 'cancelled';
        }
        if ($request->status === SignatureRequest::STATUS_FAILED) {
            return 'failed';
        }
        if ($request->status === SignatureRequest::STATUS_COMPLETED) {
            return 'signed';
        }
        if ($request->status === SignatureRequest::STATUS_EXPIRED || $request->isExpiredByTime(now())) {
            return 'expired';
        }
        if ($signer->status === SignatureSigner::STATUS_SIGNED) {
            return 'in_progress';
        }

        return $signer->status === SignatureSigner::STATUS_INVITED ? 'to_sign' : 'in_progress';
    }

    private static function isRemote(SignatureRequest $request): bool
    {
        try {
            return app(SignatureProviderManager::class)->driver($request->provider) instanceof SealsDocumentRemotely;
        } catch (\Throwable) {
            return true; // unknown provider : never pretend an in-app signature is available
        }
    }
}
