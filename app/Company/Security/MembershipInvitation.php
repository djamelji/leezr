<?php

namespace App\Company\Security;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Notifications\Members\CompanyInvitationNotification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * M7 LOT 2 — single authority for attaching a PRE-EXISTING account to a company.
 *
 * Invitation sent → acceptance by the recipient → membership activated. A company can never
 * silently grant itself a hold on an account it did not create: the membership is created
 * PENDING (`acceptance_pending_at`) and SUSPENDED (`suspended_at`), so every existing access
 * check (SetCompanyContext, User::hasCompanyPermission, company switcher, last-active-admin)
 * already keeps it out. Only the recipient — authenticated as that very account — activates it.
 * An account created BY the invitation keeps the historical flow (setting its password is the
 * explicit acceptance).
 */
class MembershipInvitation
{
    public const REASON = 'pending_acceptance';

    /** Marks a freshly created membership of a pre-existing account as pending and notifies its owner. */
    public static function requireAcceptance(Company $company, Membership $membership, ?User $actor): Membership
    {
        $membership->forceFill([
            'acceptance_pending_at' => now(),
            'invited_by' => $actor?->id,
            'suspended_at' => now(),
            'suspended_by' => $actor?->id,
            'suspension_reason' => self::REASON,
        ])->save();

        self::notify($company, $membership, $actor);

        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_INVITATION_SENT, 'membership', (string) $membership->id, [
            'actorId' => $actor?->id, 'actorType' => 'user',
            'metadata' => ['user_id' => $membership->user_id, 'role_id' => $membership->company_role_id],
        ]);

        return $membership;
    }

    /** Re-sends the invitation e-mail of a pending membership. */
    public static function resend(Company $company, Membership $membership, ?User $actor): void
    {
        if (! $membership->isPendingAcceptance()) {
            throw ValidationException::withMessages(['access' => 'Aucune invitation en attente pour ce compte.']);
        }
        self::notify($company, $membership, $actor);
    }

    /**
     * Guard for every path that would act on the account before its owner accepted (reactivation,
     * credentials): refused (422) while the invitation is pending.
     */
    public static function assertAccepted(Membership $membership): void
    {
        if ($membership->isPendingAcceptance()) {
            throw ValidationException::withMessages([
                'access' => "Invitation en attente : seule la personne invitée peut l'accepter. Aucune action sur ce compte n'est possible avant son acceptation.",
            ]);
        }
    }

    /** Pending invitations of a user, across companies (no company context: explicit scope removal). */
    public static function pendingFor(User $user): Collection
    {
        return Membership::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('acceptance_pending_at')
            ->with(['company:id,name', 'companyRole:id,name'])
            ->orderBy('acceptance_pending_at')
            ->get();
    }

    /** The recipient accepts: the membership becomes active. Null when it is not HIS pending invitation. */
    public static function accept(User $user, int $membershipId): ?Membership
    {
        $membership = self::ownPending($user, $membershipId);
        if (! $membership) {
            return null;
        }

        $membership->forceFill([
            'acceptance_pending_at' => null,
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ])->save();

        app(AuditLogger::class)->logCompany($membership->company_id, AuditAction::MEMBER_INVITATION_ACCEPTED, 'membership', (string) $membership->id, [
            'actorId' => $user->id, 'actorType' => 'user', 'metadata' => ['user_id' => $user->id, 'invited_by' => $membership->invited_by],
        ]);

        return $membership;
    }

    /** The recipient declines: the membership is removed and a linked HR record is unlinked (HR data kept). */
    public static function decline(User $user, int $membershipId): bool
    {
        $membership = self::ownPending($user, $membershipId);
        if (! $membership) {
            return false;
        }

        Employee::withoutGlobalScopes()
            ->where('company_id', $membership->company_id)->where('user_id', $user->id)
            ->update(['user_id' => null]);

        $companyId = $membership->company_id;
        $id = $membership->id;
        $membership->delete();

        app(AuditLogger::class)->logCompany($companyId, AuditAction::MEMBER_INVITATION_DECLINED, 'membership', (string) $id, [
            'actorId' => $user->id, 'actorType' => 'user', 'severity' => 'notice', 'metadata' => ['user_id' => $user->id],
        ]);

        return true;
    }

    private static function ownPending(User $user, int $membershipId): ?Membership
    {
        return Membership::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('acceptance_pending_at')
            ->where('id', $membershipId)
            ->first();
    }

    private static function notify(Company $company, Membership $membership, ?User $actor): void
    {
        $recipient = $membership->user ?? User::find($membership->user_id);
        $recipient?->notify(new CompanyInvitationNotification(
            companyName: (string) $company->name,
            inviterName: $actor ? (trim($actor->first_name.' '.$actor->last_name) ?: null) : null,
            url: url('/invitations'),
        ));
    }
}
