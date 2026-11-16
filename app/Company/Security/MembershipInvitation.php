<?php

namespace App\Company\Security;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Jobs\Members\SendMembershipInvitationJob;
use App\Notifications\Members\CompanyInvitationNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
 *
 * M7 LOT 3 — this class is also the single authority for the DELIVERY of the invitation e-mail
 * (queued job, persisted `queued` / `sent` / `failed` state, retry, audit — a mail outage is never
 * an HTTP 500 nor a false confirmation) and for its EXPIRY (`members.invitation_ttl_days`).
 */
class MembershipInvitation
{
    public const REASON = 'pending_acceptance';

    public const DELIVERY_QUEUED = 'queued';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    public const KIND_ACCEPTANCE = 'acceptance';
    public const KIND_ACCOUNT_SETUP = 'account_setup';

    /**
     * Marks a freshly created membership of a pre-existing account as pending, with its deadline.
     * The e-mail is NOT sent here : the caller invokes `deliver()` once its own writes are committed.
     */
    public static function requireAcceptance(Company $company, Membership $membership, ?User $actor): Membership
    {
        $membership->forceFill([
            'acceptance_pending_at' => now(),
            'invited_by' => $actor?->id,
            'suspended_at' => now(),
            'suspended_by' => $actor?->id,
            'suspension_reason' => self::REASON,
            'invitation_expires_at' => now()->addDays(self::ttlDays()),
            'invitation_expired_at' => null,
        ])->save();

        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_INVITATION_SENT, 'membership', (string) $membership->id, [
            'actorId' => $actor?->id, 'actorType' => 'user',
            'metadata' => ['user_id' => $membership->user_id, 'role_id' => $membership->company_role_id],
        ]);

        return $membership;
    }

    /**
     * Re-sends the invitation of a membership (pending acceptance, or account that never defined its
     * password). A pending acceptance gets a NEW deadline ; renewing an EXPIRED one takes a seat
     * again, so the quota is checked first. Returns the delivery summary (never a false « sent »).
     *
     * @throws ValidationException (422) nothing to re-send / member limit reached
     */
    public static function resend(Company $company, Membership $membership, ?User $actor): array
    {
        if ($membership->isPendingAcceptance()) {
            $wasExpired = self::isExpired($membership);
            if ($wasExpired) {
                MemberQuota::assertSeatAvailable($company);
            }
            $membership->forceFill(['invitation_expires_at' => now()->addDays(self::ttlDays()), 'invitation_expired_at' => null])->save();
            if ($wasExpired) {
                app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_INVITATION_RENEWED, 'membership', (string) $membership->id, [
                    'actorId' => $actor?->id, 'actorType' => 'user', 'metadata' => ['user_id' => $membership->user_id],
                ]);
            }
        }

        return self::deliver($company, $membership, $actor);
    }

    // ── Delivery (M7 LOT 3) ─────────────────────────────────────────────────

    /** Validity, in days, of an invitation awaiting acceptance (config `members.invitation_ttl_days`). */
    public static function ttlDays(): int
    {
        return max(1, (int) config('members.invitation_ttl_days', 14));
    }

    /** `acceptance` (pre-existing account) | `account_setup` (account created by the invitation) | null. */
    public static function kind(Membership $membership): ?string
    {
        if ($membership->isPendingAcceptance()) {
            return self::KIND_ACCEPTANCE;
        }
        $user = $membership->user ?? User::find($membership->user_id);

        return $user && ! $user->password_set_at ? self::KIND_ACCOUNT_SETUP : null;
    }

    /**
     * Hands the invitation e-mail to the queue and returns the delivery summary. The state is
     * `queued` until a worker sends it ; on the `sync` connection the outcome (`sent` / `failed`) is
     * already known when this returns. A transport failure is recorded — never thrown to the caller.
     *
     * @throws ValidationException (422) when the membership has no invitation to send
     */
    public static function deliver(Company $company, Membership $membership, ?User $actor): array
    {
        if (self::kind($membership) === null) {
            throw ValidationException::withMessages(['access' => 'Aucune invitation en attente pour ce compte.']);
        }

        $membership->forceFill(['invitation_delivery_status' => self::DELIVERY_QUEUED, 'invitation_delivery_error' => null])->save();

        try {
            SendMembershipInvitationJob::dispatch($company->id, $membership->id, $actor?->id);
        } catch (\Throwable $e) {
            // The queue itself is unreachable : the invitation exists, its e-mail is not on its way.
            self::recordFailure($membership, $actor?->id, $e);
        }

        return self::summary($membership->refresh());
    }

    /**
     * Sends the e-mail NOW (called by the job) and records the outcome on the membership.
     * Returns null on success, the short error on failure.
     */
    public static function sendNow(Membership $membership, ?int $actorId): ?string
    {
        $recipient = $membership->user ?? User::find($membership->user_id);
        $kind = self::kind($membership);
        if (! $recipient || $kind === null) {
            return null; // accepted / activated meanwhile : nothing to send
        }

        try {
            if ($kind === self::KIND_ACCEPTANCE) {
                $actor = $actorId ? User::find($actorId) : null;
                Notification::sendNow($recipient, new CompanyInvitationNotification(
                    companyName: (string) ($membership->company?->name ?? ''),
                    inviterName: $actor ? (trim($actor->first_name.' '.$actor->last_name) ?: null) : null,
                    url: url('/invitations'),
                ));
            } else {
                // ADR-812 : an activation e-mail naming the company (not the generic « password reset »).
                $actor = $actorId ? User::find($actorId) : null;
                $token = Password::broker('users')->createToken($recipient);
                Notification::sendNow($recipient, new \App\Notifications\Members\AccountActivationNotification(
                    companyName: (string) ($membership->company?->name ?? ''),
                    inviterName: $actor ? (trim($actor->first_name.' '.$actor->last_name) ?: null) : null,
                    token: $token,
                    email: $recipient->getEmailForPasswordReset(),
                    expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
                ));
            }
        } catch (\Throwable $e) {
            return self::recordFailure($membership, $actorId, $e);
        }

        $membership->forceFill(['invitation_delivery_status' => self::DELIVERY_SENT, 'invitation_sent_at' => now(), 'invitation_delivery_error' => null])->save();
        app(AuditLogger::class)->logCompany($membership->company_id, AuditAction::MEMBER_INVITATION_DELIVERED, 'membership', (string) $membership->id, [
            'actorId' => $actorId, 'actorType' => $actorId ? 'user' : 'system', 'metadata' => ['user_id' => $membership->user_id, 'kind' => $kind],
        ]);

        return null;
    }

    private static function recordFailure(Membership $membership, ?int $actorId, \Throwable $e): string
    {
        $error = Str::limit(trim(preg_replace('/\s+/', ' ', $e->getMessage())) ?: class_basename($e), 250, '…');
        $membership->forceFill(['invitation_delivery_status' => self::DELIVERY_FAILED, 'invitation_delivery_error' => $error])->save();

        Log::warning('[members.invitation] e-mail delivery failed', [
            'company_id' => $membership->company_id, 'membership_id' => $membership->id, 'user_id' => $membership->user_id, 'error' => $error,
        ]);
        app(AuditLogger::class)->logCompany($membership->company_id, AuditAction::MEMBER_INVITATION_DELIVERY_FAILED, 'membership', (string) $membership->id, [
            'actorId' => $actorId, 'actorType' => $actorId ? 'user' : 'system', 'severity' => 'warning',
            'metadata' => ['user_id' => $membership->user_id, 'error' => $error],
        ]);

        return $error;
    }

    /**
     * What an administrator may know about the invitation of a membership (null when the access is
     * already active : nothing pending). Shape shared by every API that exposes it.
     *
     * @return array{kind: string, delivery: ?string, sent_at: ?string, error: ?string, expires_at: ?string, expired: bool, link_expires_at: ?string, link_expired: bool}|null
     */
    public static function summary(?Membership $membership): ?array
    {
        if (! $membership || ($kind = self::kind($membership)) === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'delivery' => $membership->invitation_delivery_status,
            'sent_at' => $membership->invitation_sent_at?->toIso8601String(),
            'error' => $membership->invitation_delivery_status === self::DELIVERY_FAILED ? $membership->invitation_delivery_error : null,
            // (1) INVITATION expiry — acceptance by a pre-existing account only.
            'expires_at' => self::expiresAt($membership)?->toIso8601String(),
            'expired' => self::isExpired($membership),
            // (2) Password-definition LINK expiry — account created by the invitation only. The
            // account and its membership do NOT expire : a fresh link is one « resend » (or one
            // « forgot password » by the person) away. Never to be read as (1).
            'link_expires_at' => ($link = self::setupLinkExpiresAt($membership, $kind))?->toIso8601String(),
            'link_expired' => $link?->isPast() ?? false,
        ];
    }

    /** Validity end of the LAST password-definition link really sent (broker `auth.passwords.users.expire`). */
    private static function setupLinkExpiresAt(Membership $membership, string $kind): ?\Illuminate\Support\Carbon
    {
        if ($kind !== self::KIND_ACCOUNT_SETUP || $membership->invitation_sent_at === null) {
            return null;
        }

        return $membership->invitation_sent_at->copy()->addMinutes((int) config('auth.passwords.users.expire', 60));
    }

    // ── Expiry (M7 LOT 3) ───────────────────────────────────────────────────

    /**
     * EFFECTIVE deadline of a pending acceptance (null when nothing is pending).
     *
     * M7 LOT 4 — compatibility rule : an invitation created before deadlines existed (ADR-768,
     * `invitation_expires_at` NULL) is NOT eternal. Its deadline is DERIVED : the day it was sent
     * (`acceptance_pending_at`) + the configured validity. Nothing is rewritten in the database —
     * the rule is applied wherever expiry matters (acceptance, lists, quota, scheduled trace), so an
     * old invitation can neither be accepted forever nor disappear without a trace. Renewing it
     * (`resend`) stores a real deadline like any other invitation.
     */
    public static function expiresAt(Membership $membership): ?\Illuminate\Support\Carbon
    {
        if (! $membership->isPendingAcceptance()) {
            return null;
        }

        return $membership->invitation_expires_at
            ?? $membership->acceptance_pending_at->copy()->addDays(self::ttlDays());
    }

    /** A pending acceptance whose (effective) deadline has passed : can no longer be accepted, frees its seat. */
    public static function isExpired(Membership $membership): bool
    {
        return self::expiresAt($membership)?->isPast() ?? false;
    }

    /**
     * SQL form of « this membership still holds a seat / may still become an access » : everything
     * except an EXPIRED pending acceptance (stored deadline, or derived one for a legacy row).
     * Single definition shared by the quota and the scheduled trace.
     */
    public static function whereNotExpired($query)
    {
        return $query->where(fn ($q) => $q->whereNull('acceptance_pending_at')
            ->orWhere('invitation_expires_at', '>', now())
            ->orWhere(fn ($w) => $w->whereNull('invitation_expires_at')->where('acceptance_pending_at', '>', now()->subDays(self::ttlDays()))));
    }

    /** Is it an EXPIRED invitation of this very user ? (clear 410 instead of an anonymous 404) */
    public static function isOwnExpired(User $user, int $membershipId): bool
    {
        $membership = self::ownPending($user, $membershipId);

        return $membership !== null && self::isExpired($membership);
    }

    /**
     * Writes the audit event of every expired invitation not traced yet (scheduled task). Cross-
     * tenant system read ; each event carries the membership's own company. Idempotent.
     */
    public static function traceExpired(): int
    {
        $due = Membership::withoutGlobalScopes()
            ->whereNotNull('acceptance_pending_at')
            ->whereNull('invitation_expired_at')
            ->where(fn ($q) => $q->where('invitation_expires_at', '<=', now())
                // legacy row without stored deadline : derived deadline (see expiresAt)
                ->orWhere(fn ($w) => $w->whereNull('invitation_expires_at')->where('acceptance_pending_at', '<=', now()->subDays(self::ttlDays()))))
            ->get();

        foreach ($due as $membership) {
            $membership->forceFill(['invitation_expired_at' => now()])->save();
            app(AuditLogger::class)->logCompany($membership->company_id, AuditAction::MEMBER_INVITATION_EXPIRED, 'membership', (string) $membership->id, [
                'actorType' => 'system', 'severity' => 'notice',
                'metadata' => [
                    'user_id' => $membership->user_id, 'invited_by' => $membership->invited_by,
                    'expired_at' => self::expiresAt($membership)?->toIso8601String(),
                    'derived_deadline' => $membership->invitation_expires_at === null, // legacy invitation (no stored deadline)
                ],
            ]);
        }

        return $due->count();
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
            ->get()
            ->reject(fn (Membership $m) => self::isExpired($m)) // expired (stored or derived deadline) : no longer acceptable
            ->values();
    }

    /** The recipient accepts: the membership becomes active. Null when it is not HIS pending invitation. */
    public static function accept(User $user, int $membershipId): ?Membership
    {
        $membership = self::ownPending($user, $membershipId);
        if (! $membership || self::isExpired($membership)) {
            return null; // an expired invitation is never activated (the caller answers 410)
        }

        $membership->forceFill([
            'acceptance_pending_at' => null,
            'invitation_expires_at' => null,
            'invitation_expired_at' => null,
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
}
