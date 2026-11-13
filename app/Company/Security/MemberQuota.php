<?php

namespace App\Company\Security;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * M7 LOT 3 — the SINGLE authority for the member quota of a company (plan limit `members`).
 *
 * Rule : a seat is held by every membership that is, or may still become, an access —
 *   - accepted / active / suspended memberships ;
 *   - accounts invited and not yet activated (password not defined) ;
 *   - invitations of existing accounts PENDING acceptance and not expired.
 * A seat is freed when the invitation is DECLINED or CANCELLED (the membership is removed) or
 * EXPIRED (the membership is kept for the audit trail but no longer counted). Renewing an
 * expired invitation takes a seat again, so it goes through `assertSeatAvailable()` too.
 *
 * Every path that adds an access (Members invitation, Workforce access grant, renewal) asks this
 * class — a company can neither exceed its limit by multiplying invitations nor stay blocked by
 * invitations nobody answered.
 */
final class MemberQuota
{
    public static function limit(Company $company): ?int
    {
        return CompanyEntitlements::memberLimit($company);
    }

    public static function used(Company $company): int
    {
        return $company->memberships()
            ->where(fn ($q) => $q->whereNull('acceptance_pending_at')
                ->orWhereNull('invitation_expires_at')
                ->orWhere('invitation_expires_at', '>', now()))
            ->count();
    }

    public static function isFull(Company $company): bool
    {
        $limit = self::limit($company);

        return $limit !== null && self::used($company) >= $limit;
    }

    /** @throws ValidationException (422) */
    public static function assertSeatAvailable(Company $company): void
    {
        if (self::isFull($company)) {
            $limit = self::limit($company);

            throw ValidationException::withMessages(['access' => "Limite de membres atteinte ({$limit}). Impossible d'ajouter un accès."]);
        }
    }
}
