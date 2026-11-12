<?php

namespace App\Company\Security;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-767 — SINGLE server authority for edits of a GLOBAL identity made from a company.
 *
 * A `User` (name, password) is global: the same row is used by every company the person
 * belongs to. A company may administer that identity ONLY while it is the sole company of the
 * user. As soon as the user also belongs to another company, the identity is SHARED and no
 * company administrator may rewrite it (rename, set a password) — otherwise company A could
 * alter, or take over, an account used in company B. The person keeps full control through
 * their own profile; a reset LINK (sent to the user's own mailbox) stays allowed.
 *
 * Company-scoped data (membership, role, dynamic fields, the HR `Employee` record) is not
 * concerned: it is owned by the company and stays editable.
 */
class GlobalIdentityPolicy
{
    /** True when the user also belongs to at least one OTHER company. */
    public static function isShared(int $companyId, User $user): bool
    {
        // Cross-company question by nature → the tenant global scope (CompanyScope) is lifted on
        // purpose ; only the EXISTENCE of another membership is read, never its content.
        return Membership::withoutGlobalScopes()->where('user_id', $user->id)->where('company_id', '!=', $companyId)->exists();
    }

    /**
     * @throws ValidationException (422) when the identity is shared with another company.
     */
    public static function assertCompanyMayEdit(Company $company, User $user, string $field = 'identity'): void
    {
        if (static::isShared((int) $company->id, $user)) {
            throw ValidationException::withMessages([
                $field => "Ce compte est aussi utilisé dans une autre entreprise : son identité ne peut être modifiée que par la personne elle-même.",
            ]);
        }
    }

    /**
     * Keeps only the global identity fields that REALLY change, and authorizes the change.
     * An unchanged value resubmitted by a form is never a modification (no false refusal).
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException (422)
     */
    public static function authorizedChanges(Company $company, User $user, array $input, array $fields = ['first_name', 'last_name']): array
    {
        $changes = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $user->{$field}) {
                $changes[$field] = $input[$field];
            }
        }
        if ($changes !== []) {
            static::assertCompanyMayEdit($company, $user, array_key_first($changes));
        }

        return $changes;
    }
}
