<?php

namespace App\Modules\Platform\Support\Http;

use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Support\SupportMessage;
use App\Core\Support\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSupportMessageController
{
    public function index(int $ticketId): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($ticketId);

        $messages = $ticket->messages()
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request, int $ticketId): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $platformUser = $request->user('platform');

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'sender_id' => $platformUser->id,
            'body' => $validated['body'],
        ]);

        // Track first response time
        if (!$ticket->first_response_at) {
            $ticket->update(['first_response_at' => now()]);
        }

        $ticket->update([
            'last_message_at' => now(),
            'status' => 'waiting_customer',
        ]);

        // Auto-assign if not assigned
        if (!$ticket->assigned_to_platform_user_id) {
            $ticket->update(['assigned_to_platform_user_id' => $platformUser->id]);
        }

        // Notify the ticket creator
        $creator = User::find($ticket->created_by_user_id);
        if ($creator) {
            $company = $ticket->company;
            NotificationDispatcher::send(
                'support.ticket_replied',
                collect([$creator]),
                [
                    'ticket_id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'sender_name' => $platformUser->first_name . ' ' . $platformUser->last_name,
                    'link' => "/company/support/{$ticket->id}",
                ],
                $company,
            );
        }

        return response()->json($message, 201);
    }

    public function storeInternal(Request $request, int $ticketId): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $platformUser = $request->user('platform');

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'sender_id' => $platformUser->id,
            'body' => $validated['body'],
            'is_internal' => true,
        ]);

        return response()->json($message, 201);
    }
}
