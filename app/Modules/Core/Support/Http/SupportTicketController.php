<?php

namespace App\Modules\Core\Support\Http;

use App\Core\Notifications\NotificationDispatcher;
use App\Core\Support\SupportMessage;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $query = SupportTicket::where('company_id', $company->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 15), 50);

        return response()->json(
            $query->paginate($perPage),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'category' => 'nullable|string|in:billing,technical,general',
        ]);

        $ticket = SupportTicket::create([
            'company_id' => $company->id,
            'created_by_user_id' => $user->id,
            'subject' => $validated['subject'],
            'category' => $validated['category'] ?? 'general',
            'last_message_at' => now(),
        ]);

        // Create initial message
        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'company_user',
            'sender_id' => $user->id,
            'body' => $validated['body'],
        ]);

        // Notify platform admins
        $admins = PlatformUser::all();
        NotificationDispatcher::send(
            'support.ticket_created',
            $admins,
            [
                'ticket_id' => $ticket->id,
                'subject' => $ticket->subject,
                'company_name' => $company->name,
                'link' => "/platform/support/{$ticket->id}",
            ],
        );

        return response()->json($ticket->load('messages'), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $ticket = SupportTicket::where('company_id', $company->id)
            ->with(['creator:id,first_name,last_name', 'assignee:id,first_name,last_name'])
            ->findOrFail($id);

        return response()->json($ticket);
    }
}
