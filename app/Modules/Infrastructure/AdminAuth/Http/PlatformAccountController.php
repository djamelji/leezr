<?php

namespace App\Modules\Infrastructure\AdminAuth\Http;

use App\Core\Notifications\NotificationTopic;
use App\Core\Notifications\PlatformNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PlatformAccountController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user('platform');

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:platform_users,email,'.$user->id],
        ]);

        $user->update($validated);

        return response()->json(['user' => $user->fresh()]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user('platform');

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        $userId = $request->user('platform')->id;

        $topics = NotificationTopic::active()
            ->forScope('platform')
            ->orderBy('sort_order')
            ->get();

        $preferences = PlatformNotificationPreference::where('platform_user_id', $userId)
            ->pluck('channels', 'topic_key');

        $data = $topics->map(fn ($topic) => [
            'key' => $topic->key,
            'label' => $topic->label,
            'category' => $topic->category,
            'icon' => $topic->icon,
            'severity' => $topic->severity,
            'channels' => $preferences[$topic->key] ?? $topic->default_channels,
            'default_channels' => $topic->default_channels,
        ]);

        return response()->json(['preferences' => $data]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $userId = $request->user('platform')->id;

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.topic_key' => ['required', 'string'],
            'preferences.*.channels' => ['required', 'array'],
            'preferences.*.channels.*' => ['string', 'in:in_app,email'],
        ]);

        foreach ($validated['preferences'] as $pref) {
            PlatformNotificationPreference::updateOrCreate(
                [
                    'platform_user_id' => $userId,
                    'topic_key' => $pref['topic_key'],
                ],
                [
                    'channels' => $pref['channels'],
                ],
            );
        }

        return response()->json(['message' => 'Preferences updated.']);
    }
}
