<?php

namespace App\Modules\Platform\Support\Http;

use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Support\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSupportTicketController
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with([
            'company:id,name',
            'creator:id,first_name,last_name',
            'assignee:id,first_name,last_name',
        ])->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_platform_user_id', $request->input('assigned_to'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 50);

        return response()->json($query->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $ticket = SupportTicket::with([
            'company:id,name,slug',
            'creator:id,first_name,last_name,email',
            'assignee:id,first_name,last_name',
        ])->findOrFail($id);

        return response()->json($ticket);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);
        $platformUser = $request->user('platform');

        $validated = $request->validate([
            'platform_user_id' => 'nullable|exists:platform_users,id',
        ]);

        $assigneeId = $validated['platform_user_id'] ?? $platformUser->id;

        $ticket->update([
            'assigned_to_platform_user_id' => $assigneeId,
            'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ]);

        return response()->json($ticket->fresh()->load('assignee:id,first_name,last_name'));
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $ticket->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        // Notify the ticket creator
        $creator = User::find($ticket->created_by_user_id);
        if ($creator) {
            $company = $ticket->company;
            NotificationDispatcher::send(
                'support.ticket_resolved',
                collect([$creator]),
                [
                    'ticket_id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'link' => "/company/support/{$ticket->id}",
                ],
                $company,
            );
        }

        return response()->json($ticket->fresh());
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);
        $platformUser = $request->user('platform');

        $ticket->update([
            'status' => 'closed',
            'closed_by_platform_user_id' => $platformUser->id,
            'resolved_at' => $ticket->resolved_at ?? now(),
        ]);

        return response()->json($ticket->fresh());
    }

    public function updatePriority(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'priority' => 'required|string|in:low,normal,high,urgent',
        ]);

        $ticket->update(['priority' => $validated['priority']]);

        return response()->json($ticket->fresh());
    }

    public function metrics(): JsonResponse
    {
        $open = SupportTicket::where('status', 'open')->count();
        $inProgress = SupportTicket::where('status', 'in_progress')->count();
        $waitingCustomer = SupportTicket::where('status', 'waiting_customer')->count();
        $resolved = SupportTicket::where('status', 'resolved')->count();

        $unassigned = SupportTicket::whereNull('assigned_to_platform_user_id')
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        return response()->json([
            'open' => $open,
            'in_progress' => $inProgress,
            'waiting_customer' => $waitingCustomer,
            'resolved' => $resolved,
            'unassigned' => $unassigned,
        ]);
    }
}
