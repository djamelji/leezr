<?php

namespace App\Company\Http\Controllers;

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

        $user = $membership->user;
        $token = Password::broker('users')->createToken($user);
        $user->sendPasswordResetNotification($token);

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

        $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
        ]);

        $membership->user->forceFill([
            'password' => $request->input('password'),
        ])->save();

        return response()->json([
            'message' => 'Password set for '.$membership->user->display_name.'.',
        ]);
    }
}
