<?php

namespace App\Modules\Platform\Notifications\Http;

use App\Core\Notifications\NotificationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformNotificationController
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user('platform');

        $query = NotificationEvent::forRecipient($admin)
            ->orderByDesc('created_at');

        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->unread();
        }

        $perPage = min((int) $request->input('per_page', 20), 50);

        return response()->json($query->paginate($perPage));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $admin = $request->user('platform');

        $count = NotificationEvent::forRecipient($admin)
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $admin = $request->user('platform');

        $event = NotificationEvent::where('id', $id)
            ->forRecipient($admin)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $event->markRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $admin = $request->user('platform');

        NotificationEvent::forRecipient($admin)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $request->user('platform');

        $event = NotificationEvent::where('id', $id)
            ->forRecipient($admin)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $event->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
