<?php

namespace App\Modules\Platform\Notifications\Http;

use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformNotificationTopicController
{
    public function index(): JsonResponse
    {
        $topics = NotificationTopic::orderBy('sort_order')
            ->orderBy('category')
            ->get();

        // Add delivery stats (last 7 days)
        $stats = NotificationEvent::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('topic_key, COUNT(*) as count')
            ->groupBy('topic_key')
            ->pluck('count', 'topic_key');

        $data = $topics->map(fn ($t) => [
            ...$t->toArray(),
            'delivery_count_7d' => $stats[$t->key] ?? 0,
        ]);

        return response()->json(['topics' => $data]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $topic = NotificationTopic::findOrFail($key);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'string', 'max:255'],
            'severity' => ['sometimes', 'string', 'in:info,success,warning,error'],
            'default_channels' => ['sometimes', 'array'],
            'default_channels.*' => ['string', 'in:in_app,email'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $topic->update($validated);

        return response()->json(['topic' => $topic->fresh()]);
    }

    public function toggle(string $key): JsonResponse
    {
        $topic = NotificationTopic::findOrFail($key);
        $topic->update(['is_active' => ! $topic->is_active]);

        return response()->json([
            'topic' => $topic->fresh(),
            'message' => $topic->is_active ? 'Topic activated.' : 'Topic deactivated.',
        ]);
    }
}
