<?php

namespace App\Modules\Core\Notifications\Http;

use App\Core\Notifications\NotificationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $query = NotificationEvent::forRecipient($user)
            ->where('company_id', $company->id)
            ->orderByDesc('created_at');

        // Filters
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->unread();
        }
        if ($request->filled('category')) {
            $query->where('topic_key', 'like', $request->input('category') . '.%');
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $count = NotificationEvent::forRecipient($user)
            ->where('company_id', $company->id)
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $event = NotificationEvent::where('id', $id)
            ->forRecipient($user)
            ->where('company_id', $company->id)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $event->markRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        NotificationEvent::forRecipient($user)
            ->where('company_id', $company->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $event = NotificationEvent::where('id', $id)
            ->forRecipient($user)
            ->where('company_id', $company->id)
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $event->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
