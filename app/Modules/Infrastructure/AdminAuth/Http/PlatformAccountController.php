<?php

namespace App\Modules\Infrastructure\AdminAuth\Http;

use App\Core\Notifications\NotificationTopic;
use App\Core\Notifications\NotificationTopicRegistry;
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

    /**
     * Return notification preferences.
     * ADR-382: super_admin gets granular per-topic control,
     * other admins get category bundles (same UX as company).
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $admin = $request->user('platform');
        $allowedCategories = NotificationTopicRegistry::platformCategoriesForAdmin($admin);

        $topics = NotificationTopic::active()
            ->forScope('platform')
            ->whereIn('category', $allowedCategories)
            ->orderBy('sort_order')
            ->get();

        $preferences = PlatformNotificationPreference::where('platform_user_id', $admin->id)
            ->pluck('channels', 'topic_key');

        // Super admin: granular per-topic view
        if ($admin->isSuperAdmin()) {
            $data = $topics->map(fn ($topic) => [
                'key' => $topic->key,
                'label' => $topic->label,
                'category' => $topic->category,
                'icon' => $topic->icon,
                'severity' => $topic->severity,
                'channels' => $preferences[$topic->key] ?? $topic->default_channels,
                'default_channels' => $topic->default_channels,
            ]);

            return response()->json([
                'mode' => 'granular',
                'preferences' => $data,
                'available_categories' => $allowedCategories,
            ]);
        }

        // Other admins: category bundles
        $grouped = $topics->groupBy('category');
        $bundles = [];

        foreach ($grouped as $category => $categoryTopics) {
            $meta = NotificationTopicRegistry::PLATFORM_BUNDLE_META[$category] ?? [];
            $locked = in_array($category, NotificationTopicRegistry::LOCKED_CATEGORIES);

            $allInApp = $categoryTopics->every(
                fn ($t) => in_array('in_app', $preferences[$t->key] ?? $t->default_channels),
            );
            $allEmail = $categoryTopics->every(
                fn ($t) => in_array('email', $preferences[$t->key] ?? $t->default_channels),
            );

            $bundles[] = [
                'category' => $category,
                'icon' => $meta['icon'] ?? 'tabler-bell',
                'color' => $meta['color'] ?? 'primary',
                'in_app' => $locked ? true : $allInApp,
                'email' => $allEmail,
                'locked' => $locked,
                'topic_count' => $categoryTopics->count(),
            ];
        }

        return response()->json([
            'mode' => 'bundles',
            'bundles' => $bundles,
            'available_categories' => $allowedCategories,
        ]);
    }

    /**
     * Update notification preferences.
     * ADR-382: super_admin updates per-topic, others update per-bundle.
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $admin = $request->user('platform');
        $allowedCategories = NotificationTopicRegistry::platformCategoriesForAdmin($admin);

        // Super admin: per-topic update
        if ($admin->isSuperAdmin() && $request->has('preferences')) {
            $validated = $request->validate([
                'preferences' => ['required', 'array'],
                'preferences.*.topic_key' => ['required', 'string'],
                'preferences.*.channels' => ['required', 'array'],
                'preferences.*.channels.*' => ['string', 'in:in_app,email'],
            ]);

            $allowedTopicKeys = NotificationTopic::active()
                ->forScope('platform')
                ->whereIn('category', $allowedCategories)
                ->pluck('key')
                ->toArray();

            foreach ($validated['preferences'] as $pref) {
                if (! in_array($pref['topic_key'], $allowedTopicKeys)) {
                    continue;
                }

                PlatformNotificationPreference::updateOrCreate(
                    ['platform_user_id' => $admin->id, 'topic_key' => $pref['topic_key']],
                    ['channels' => $pref['channels']],
                );
            }

            return response()->json(['message' => 'Preferences updated.']);
        }

        // Other admins: bundle update
        $validated = $request->validate([
            'bundles' => ['required', 'array'],
            'bundles.*.category' => ['required', 'string'],
            'bundles.*.in_app' => ['required', 'boolean'],
            'bundles.*.email' => ['required', 'boolean'],
        ]);

        foreach ($validated['bundles'] as $bundle) {
            if (! in_array($bundle['category'], $allowedCategories)) {
                continue;
            }

            $locked = in_array($bundle['category'], NotificationTopicRegistry::LOCKED_CATEGORIES);

            $channels = [];
            if ($locked || $bundle['in_app']) {
                $channels[] = 'in_app';
            }
            if ($bundle['email']) {
                $channels[] = 'email';
            }

            $topicKeys = NotificationTopic::active()
                ->forScope('platform')
                ->where('category', $bundle['category'])
                ->pluck('key');

            foreach ($topicKeys as $topicKey) {
                PlatformNotificationPreference::updateOrCreate(
                    ['platform_user_id' => $admin->id, 'topic_key' => $topicKey],
                    ['channels' => $channels],
                );
            }
        }

        return response()->json(['message' => 'Preferences updated.']);
    }
}
