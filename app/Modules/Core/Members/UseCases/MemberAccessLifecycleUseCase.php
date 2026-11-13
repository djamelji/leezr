<?php

namespace App\Modules\Core\Members\UseCases;

use App\Company\RBAC\MembershipSecurityPolicy;
use App\Company\Security\MembershipInvitation;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-768 (M7 LOT 2) — access lifecycle of an ACCOUNT (Membership), with or without an HR record.
 *
 * The Workforce path (`EmployeeAccessService`) only reaches a membership through an Employee ; a
 * plain account (no HR record, or a company without Workforce) had no suspend/restore at all. Same
 * guards, same single authority (`MembershipSecurityPolicy`) : never the owner, never oneself, never
 * the last active administrator. Nothing is deleted — the membership and all data are kept.
 */
class MemberAccessLifecycleUseCase
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function suspend(Company $company, Membership $membership, User $actor, ?string $reason = null): Membership
    {
        $this->assertNotOwner($membership);
        MembershipSecurityPolicy::assertCanModifyAccess($company, $membership, $actor);

        if (! $membership->isSuspended()) {
            $membership->forceFill(['suspended_at' => now(), 'suspended_by' => $actor->id, 'suspension_reason' => $reason])->save();
            $this->log($company, $membership, $actor, AuditAction::MEMBER_SUSPENDED, ['reason' => $reason], 'warning');
        }

        return $membership;
    }

    public function restore(Company $company, Membership $membership, User $actor): Membership
    {
        // An invitation the recipient has not accepted yet is NOT a suspension an administrator may lift.
        MembershipInvitation::assertAccepted($membership);

        if ($membership->isSuspended()) {
            $membership->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null])->save();
            $this->log($company, $membership, $actor, AuditAction::MEMBER_REACTIVATED, []);
        }

        return $membership;
    }

    /**
     * Re-send the invitation : pending acceptance (new deadline), or an account that never defined
     * its password. Returns the REAL delivery summary (M7 LOT 3) — `failed` when the e-mail could
     * not be sent, never a false confirmation.
     */
    public function resendInvitation(Company $company, Membership $membership, User $actor): array
    {
        $summary = MembershipInvitation::resend($company, $membership, $actor);
        $this->log($company, $membership, $actor, 'member.invitation_resent', ['delivery' => $summary['delivery'] ?? null]);

        return $summary;
    }

    private function assertNotOwner(Membership $membership): void
    {
        if ($membership->isOwner()) {
            throw ValidationException::withMessages(['access' => "L'accès du propriétaire de l'entreprise ne peut pas être suspendu."]);
        }
    }

    private function log(Company $company, Membership $membership, User $actor, string $action, array $metadata, string $severity = 'info'): void
    {
        $this->audit->logCompany($company->id, $action, 'membership', (string) $membership->id, [
            'actorId' => $actor->id, 'actorType' => 'user', 'severity' => $severity,
            'metadata' => $metadata + ['user_id' => $membership->user_id],
        ]);
    }
}
