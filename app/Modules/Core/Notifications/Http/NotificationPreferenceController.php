<?php

namespace App\Modules\Core\Notifications\Http;

use App\Core\Notifications\NotificationPreference;
use App\Core\Notifications\NotificationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $topics = NotificationTopic::active()
            ->forScope('company')
            ->orderBy('sort_order')
            ->get();

        $preferences = NotificationPreference::where('user_id', $userId)
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

    public function update(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.topic_key' => ['required', 'string'],
            'preferences.*.channels' => ['required', 'array'],
            'preferences.*.channels.*' => ['string', 'in:in_app,email'],
        ]);

        foreach ($validated['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $userId,
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
