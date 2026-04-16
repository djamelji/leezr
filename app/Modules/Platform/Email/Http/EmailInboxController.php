<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailRecipient;
use App\Core\Email\EmailService;
use App\Core\Email\EmailThread;
use App\Notifications\Email\ManualEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailInboxController
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailThread::with(['company:id,name'])
            ->orderByDesc('last_message_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->query('unread')) {
            $query->withUnread();
        }
        if ($companyId = $request->query('company_id')) {
            $query->where('company_id', $companyId);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('subject', 'LIKE', "%{$search}%")
                ->orWhere('participant_email', 'LIKE', "%{$search}%")
                ->orWhere('participant_name', 'LIKE', "%{$search}%")
            );
        }

        $threads = $query->paginate(20);

        // Attach last message preview to each thread
        $threadIds = collect($threads->items())->pluck('id');
        $lastMessages = EmailLog::whereIn('thread_id', $threadIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('thread_id')
            ->map(fn ($msgs) => $msgs->first());

        $items = collect($threads->items())->map(function ($thread) use ($lastMessages) {
            $t = $thread->toArray();
            $last = $lastMessages->get($thread->id);
            $t['last_message'] = $last ? [
                'subject' => $last->subject,
                'body_text' => Str::limit($last->body_text ?? strip_tags($last->body_html ?? ''), 120),
                'direction' => $last->direction,
                'created_at' => $last->created_at?->toISOString(),
            ] : null;

            return $t;
        });

        // Stats
        $totalUnread = EmailThread::where('unread_count', '>', 0)->count();

        return response()->json([
            'data' => $items,
            'current_page' => $threads->currentPage(),
            'last_page' => $threads->lastPage(),
            'total' => $threads->total(),
            'stats' => [
                'total_unread' => $totalUnread,
                'open' => EmailThread::open()->count(),
                'closed' => EmailThread::closed()->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $thread = EmailThread::with('company:id,name')->findOrFail($id);

        $messages = EmailLog::where('thread_id', $id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'message_id' => $msg->message_id,
                'direction' => $msg->direction,
                'from_email' => $msg->from_email,
                'recipient_email' => $msg->recipient_email,
                'recipient_name' => $msg->recipient_name,
                'subject' => $msg->subject,
                'body_html' => $msg->body_html,
                'body_text' => $msg->body_text,
                'status' => $msg->status,
                'is_read' => $msg->is_read,
                'created_at' => $msg->created_at?->toISOString(),
                'sent_at' => $msg->sent_at?->toISOString(),
            ]);

        // Mark all messages as read
        $thread->markAllRead();

        return response()->json([
            'thread' => $thread,
            'messages' => $messages,
        ]);
    }

    public function compose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
            'company_id' => 'nullable|exists:companies,id',
        ]);

        $company = $validated['company_id']
            ? \App\Core\Models\Company::find($validated['company_id'])
            : null;

        // Create thread
        $thread = EmailThread::create([
            'subject' => $validated['subject'],
            'company_id' => $company?->id,
            'participant_email' => $validated['to'],
            'participant_name' => $validated['to_name'] ?? null,
            'status' => 'open',
            'last_message_at' => now(),
            'message_count' => 1,
            'unread_count' => 0,
        ]);

        // Create a simple notifiable for the recipient
        $recipient = new EmailRecipient(
            $validated['to'],
            $validated['to_name'] ?? null,
        );

        $notification = new ManualEmailNotification(
            $validated['subject'],
            $validated['body'],
        );

        $service = app(EmailService::class);
        $log = $service->send($notification, $recipient, 'manual.compose', $company, [
            'thread_id' => $thread->id,
            'manual' => true,
        ]);

        // Link log to thread and store body
        $log->update([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'is_read' => true,
            'body_html' => $validated['body'],
            'body_text' => strip_tags($validated['body']),
        ]);

        return response()->json([
            'message' => 'Email sent.',
            'thread' => $thread->fresh(),
            'log' => $log,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);

        $validated = $request->validate([
            'body' => 'required|string|max:50000',
        ]);

        // Find the last message to reply to
        $lastMessage = EmailLog::where('thread_id', $id)
            ->orderByDesc('created_at')
            ->first();

        $recipient = new EmailRecipient(
            $thread->participant_email,
            $thread->participant_name,
        );

        $notification = new ManualEmailNotification(
            "Re: {$thread->subject}",
            $validated['body'],
        );

        $service = app(EmailService::class);
        $log = $service->send($notification, $recipient, 'manual.reply', $thread->company, [
            'thread_id' => $thread->id,
            'in_reply_to' => $lastMessage?->message_id,
            'manual' => true,
        ]);

        // Link log to thread with threading headers
        $log->update([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'is_read' => true,
            'in_reply_to' => $lastMessage?->message_id,
            'body_html' => $validated['body'],
            'body_text' => strip_tags($validated['body']),
            'headers' => array_merge($log->headers ?? [], [
                'In-Reply-To' => $lastMessage ? "<{$lastMessage->message_id}>" : null,
                'References' => $lastMessage ? "<{$lastMessage->message_id}>" : null,
            ]),
        ]);

        // Update thread
        $thread->update([
            'last_message_at' => now(),
            'message_count' => $thread->messages()->count(),
        ]);

        return response()->json([
            'message' => 'Reply sent.',
            'log' => $log,
        ]);
    }

    public function markRead(int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);
        $thread->markAllRead();

        return response()->json(['message' => 'Thread marked as read.']);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:open,closed,archived',
        ]);

        $thread->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Thread status updated.',
            'thread' => $thread->fresh(),
        ]);
    }
}
