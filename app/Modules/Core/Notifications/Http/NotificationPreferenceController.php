<?php

namespace App\Modules\Core\Notifications\Http;

use App\Core\Notifications\NotificationPreference;
use App\Core\Notifications\NotificationTopic;
use App\Core\Notifications\NotificationTopicRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController
{
    /**
     * Return notification preference bundles filtered by user permissions.
     * ADR-382: 1 bundle per category (not per topic).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $request->attributes->get('company');

        $allowedCategories = NotificationTopicRegistry::categoriesForUser($user, $company);

        $topics = NotificationTopic::active()
            ->forScope('company')
            ->whereIn('category', $allowedCategories)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        $preferences = NotificationPreference::where('user_id', $user->id)
            ->pluck('channels', 'topic_key');

        $bundles = [];

        foreach ($topics as $category => $categoryTopics) {
            $meta = NotificationTopicRegistry::BUNDLE_META[$category] ?? [];
            $locked = in_array($category, NotificationTopicRegistry::LOCKED_CATEGORIES);

            // ALL logic: bundle channel true only if ALL topics have it
            $allInApp = $categoryTopics->every(fn ($t) => in_array('in_app', $preferences[$t->key] ?? $t->default_channels));

            $allEmail = $categoryTopics->every(fn ($t) => in_array('email', $preferences[$t->key] ?? $t->default_channels));

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
            'bundles' => $bundles,
            'available_categories' => $allowedCategories,
        ]);
    }

    /**
     * Update notification preferences by category bundle.
     * ADR-382: applies to ALL topics in the category.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'bundles' => ['required', 'array'],
            'bundles.*.category' => ['required', 'string'],
            'bundles.*.in_app' => ['required', 'boolean'],
            'bundles.*.email' => ['required', 'boolean'],
        ]);

        $allowedCategories = NotificationTopicRegistry::categoriesForUser($user, $company);

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
                ->forScope('company')
                ->where('category', $bundle['category'])
                ->pluck('key');

            foreach ($topicKeys as $topicKey) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'topic_key' => $topicKey],
                    ['channels' => $channels],
                );
            }
        }

        return response()->json(['message' => 'Preferences updated.']);
    }
}
