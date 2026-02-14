<?php

namespace App\Platform\Auth;

use App\Core\Auth\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PlatformPasswordResetController
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::broker('platform_users')->sendResetLink(
            $request->only('email'),
        );

        // Always return success (anti-enumeration)
        return response()->json([
            'message' => 'If an account exists for this email, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
        ]);

        $status = Password::broker('platform_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => $password,
                ])->save();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password has been reset.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }

    /**
     * Admin-triggered password reset for a platform user.
     * Generates a token and sends reset notification.
     */
    public function adminResetPassword(Request $request, int $id): JsonResponse
    {
        $user = \App\Platform\Models\PlatformUser::findOrFail($id);

        if ($user->hasRole('super_admin')) {
            return response()->json(['message' => 'Cannot modify super admin credentials.'], 403);
        }

        if ($user->id === $request->user('platform')->id) {
            return response()->json(['message' => 'Cannot modify your own credentials via this endpoint.'], 403);
        }

        $token = Password::broker('platform_users')->createToken($user);
        $user->sendPasswordResetNotification($token);

        return response()->json([
            'message' => 'Password reset link sent to ' . $user->email,
        ]);
    }
}
