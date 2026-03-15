<?php

namespace App\Modules\Core\Support\Http;

use App\Core\Notifications\NotificationDispatcher;
use App\Core\Support\SupportMessage;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportMessageController
{
    public function index(Request $request, int $ticketId): JsonResponse
    {
        $company = $request->attributes->get('company');

        $ticket = SupportTicket::where('company_id', $company->id)
            ->findOrFail($ticketId);

        $messages = $ticket->messages()
            ->where('is_internal', false)
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request, int $ticketId): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $ticket = SupportTicket::where('company_id', $company->id)
            ->findOrFail($ticketId);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'company_user',
            'sender_id' => $user->id,
            'body' => $validated['body'],
        ]);

        $ticket->update(['last_message_at' => now()]);

        // If ticket was waiting_customer, reopen
        if ($ticket->status === 'waiting_customer') {
            $ticket->update(['status' => 'open']);
        }

        // Notify assigned platform admin (or all admins if unassigned)
        $recipients = $ticket->assigned_to_platform_user_id
            ? PlatformUser::where('id', $ticket->assigned_to_platform_user_id)->get()
            : PlatformUser::all();

        NotificationDispatcher::send(
            'support.ticket_replied',
            $recipients,
            [
                'ticket_id' => $ticket->id,
                'subject' => $ticket->subject,
                'company_name' => $company->name,
                'sender_name' => $user->first_name . ' ' . $user->last_name,
                'link' => "/platform/support/{$ticket->id}",
            ],
        );

        return response()->json($message, 201);
    }
}
