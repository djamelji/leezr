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
 * An account invited and never activated keeps its seat until an administrator removes it : the
 * account exists and its owner can activate it at any time (« forgot password »), so it IS an access.
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
        // Expired pending acceptances (stored OR derived deadline — see MembershipInvitation::expiresAt)
        // no longer hold a seat ; everything else does.
        return MembershipInvitation::whereNotExpired($company->memberships())->count();
    }

    /**
     * Check-and-hold, for the transaction that ADDS a membership : the company row is locked first,
     * so two concurrent invitations cannot both pass the check and exceed the limit together.
     * (Outside a transaction it degrades to a plain check.)
     *
     * @throws ValidationException (422)
     */
    public static function reserve(Company $company): void
    {
        Company::query()->whereKey($company->id)->lockForUpdate()->first();
        self::assertSeatAvailable($company);
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
