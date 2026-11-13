<?php

namespace App\Modules\Core\Members\UseCases;

use App\Company\RBAC\CompanyRole;
use App\Company\RBAC\MembershipSecurityPolicy;
use App\Company\Security\MembershipInvitation;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-767 — invitation of a member (`POST /company/members`).
 *
 * The role attribution goes through the SINGLE server authority `MembershipSecurityPolicy`
 * (ADR-752), exactly like the role change and the collaborator-access grant: tenant isolation
 * of the role and anti-escalation (a non-administrative actor holding `members.invite` can never
 * invite someone into an administrative role). The check runs BEFORE any write, so a refused
 * invitation leaves neither a membership nor a dangling global account behind.
 */
class InviteMemberUseCase
{
    /**
     * @param  array{email: string, company_role_id: int, first_name?: string, last_name?: string}  $data
     * @return Membership|null  null when the user is already a member of the company.
     *
     * @throws \Illuminate\Validation\ValidationException (422) when the role may not be assigned.
     */
    public function execute(Company $company, array $data, User $actor): ?Membership
    {
        $role = CompanyRole::where('company_id', $company->id)->findOrFail($data['company_role_id']);
        MembershipSecurityPolicy::assertCanAssignRole($company, $role, $actor, null);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->isMemberOf($company)) {
            return null;
        }

        $membership = DB::transaction(function () use ($company, $data, $role, $user, $actor) {
            $isNewUser = $user === null;
            $user ??= User::create([
                'first_name' => $data['first_name'] ?? explode('@', $data['email'])[0],
                'last_name' => $data['last_name'] ?? '',
                'email' => $data['email'],
                'password' => null,
            ]);

            $membership = $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'user',
                'company_role_id' => $role->id,
            ]);

            if (! $isNewUser) {
                // M7 LOT 2: a PRE-EXISTING account is never attached silently — the membership stays
                // pending (no access) until its owner accepts the invitation himself.
                MembershipInvitation::requireAcceptance($company, $membership, $actor);
            }
            // An account created by this invitation : defining its password is the acceptance.

            return $membership;
        });

        // M7 LOT 3 : the e-mail leaves AFTER the commit, through the queue, and its outcome is
        // recorded on the membership — a mail outage neither rolls the invitation back nor
        // surfaces as an HTTP 500 (see MembershipInvitation::deliver).
        MembershipInvitation::deliver($company, $membership, $actor);

        return $membership->load(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name,is_administrative']);
    }
}
