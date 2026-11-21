<?php

namespace App\Modules\Core\Members\Http;

use App\Company\Security\GlobalIdentityPolicy;
use App\Company\Security\MembershipInvitation;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\AccountSecurity;
use App\Core\Auth\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class MemberCredentialController
{
    /**
     * Admin-triggered password reset for a company member.
     * Mirrors PlatformPasswordResetController::adminResetPassword().
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with('user')->findOrFail($id);

        if ($membership->isOwner()) {
            return response()->json(['message' => 'Cannot modify owner credentials.'], 403);
        }

        if ($membership->user_id === $request->user()->id) {
            return response()->json(['message' => 'Cannot modify your own credentials via this endpoint.'], 403);
        }

        // M7 LOT 2 : aucune action sur les identifiants d'un compte dont l'invitation n'est pas acceptée.
        MembershipInvitation::assertAccepted($membership);

        $user = $membership->user;
        $token = Password::broker('users')->createToken($user);
        $user->sendPasswordResetNotification($token);

        // ADR-838 — actor, target, date, action, result ; never the token
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_PASSWORD_RESET_SENT, 'user', (string) $user->id, [
            'metadata' => ['result' => 'success', 'membership_id' => $membership->id],
        ]);

        return response()->json([
            'message' => 'Password reset link sent to '.$user->email,
        ]);
    }

    /**
     * Admin-set password for a company member.
     * Mirrors PlatformUserController::setPassword().
     */
    public function setPassword(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with('user')->findOrFail($id);

        if ($membership->isOwner()) {
            return response()->json(['message' => 'Cannot modify owner credentials.'], 403);
        }

        if ($membership->user_id === $request->user()->id) {
            return response()->json(['message' => 'Cannot modify your own credentials via this endpoint.'], 403);
        }

        // M7 LOT 2 : aucune action sur les identifiants d'un compte dont l'invitation n'est pas acceptée.
        MembershipInvitation::assertAccepted($membership);

        // ADR-767 : jamais de mot de passe imposé à un compte partagé avec une autre entreprise
        // (prise de contrôle inter-tenant). Le lien de réinitialisation reste disponible.
        GlobalIdentityPolicy::assertCompanyMayEdit($company, $membership->user, 'password');

        $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
        ]);

        $target = $membership->user;
        $target->forceFill([
            'password' => $request->input('password'),
        ])->save();

        // ADR-838 — the member's open sessions are ended, the member is told, the change is audited (never the value)
        $ended = AccountSecurity::revokeSessions($target);
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_PASSWORD_SET, 'user', (string) $target->id, [
            'severity' => 'warning',
            'metadata' => ['result' => 'success', 'membership_id' => $membership->id, 'sessions_ended' => $ended],
        ]);
        AccountSecurity::notify($target, 'password_set_by_admin', $company->name);

        return response()->json([
            'message' => 'Password set for '.$membership->user->display_name.'.',
        ]);
    }
}
