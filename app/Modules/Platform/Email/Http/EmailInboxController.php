<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailAttachmentService;
use App\Core\Email\EmailBulkActionService;
use App\Core\Email\EmailComposeService;
use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use App\Core\Email\ImapFetcher;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailInboxController
{
    public function index(Request $request): JsonResponse
    {
        $folder = $request->query('folder', 'inbox');
        $starred = $request->boolean('starred');
        $label = $request->query('label');

        $query = EmailThread::with(['company:id,name'])->orderByDesc('last_message_at');

        if ($starred) {
            $query->starred();
        } elseif ($folder === 'sent') {
            $sentIds = EmailLog::where('direction', 'sent')->whereNotNull('thread_id')->distinct()->pluck('thread_id');
            $query->whereIn('id', $sentIds);
        } else {
            $query->folder($folder);
        }

        if ($label) {
            $query->withLabel($label);
        }
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
            $query->search($search);
        }

        $threads = $query->paginate(20);

        return response()->json([
            'data' => $this->enrichThreads($threads),
            'current_page' => $threads->currentPage(),
            'last_page' => $threads->lastPage(),
            'total' => $threads->total(),
            'folder_counts' => $this->folderCounts(),
            'stats' => [
                'total_unread' => EmailThread::where('unread_count', '>', 0)->count(),
                'open' => EmailThread::open()->count(),
                'closed' => EmailThread::closed()->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $thread = EmailThread::with('company:id,name')->findOrFail($id);

        $messages = EmailLog::where('thread_id', $id)->with('attachments')->orderBy('created_at')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'message_id' => $m->message_id, 'direction' => $m->direction,
                'from_email' => $m->from_email, 'recipient_email' => $m->recipient_email,
                'recipient_name' => $m->recipient_name, 'subject' => $m->subject,
                'body_html' => $m->body_html, 'body_text' => $m->body_text,
                'cc' => $m->cc, 'bcc' => $m->bcc,
                'status' => $m->status, 'is_read' => $m->is_read,
                'attachments' => $m->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'original_filename' => $a->original_filename,
                    'mime_type' => $a->mime_type,
                    'human_size' => $a->human_size,
                    'url' => $a->url,
                ]),
                'created_at' => $m->created_at?->toISOString(),
                'sent_at' => $m->sent_at?->toISOString(),
            ]);

        $thread->markAllRead();

        return response()->json(['thread' => $thread, 'messages' => $messages]);
    }

    public function compose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
            'cc' => 'nullable|string|max:1000',
            'bcc' => 'nullable|string|max:1000',
            'company_id' => 'nullable|exists:companies,id',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
        ]);

        [$thread, $log] = app(EmailComposeService::class)->compose($validated);

        if (! empty($validated['attachment_ids'])) {
            app(EmailAttachmentService::class)->attach($log, $validated['attachment_ids']);
        }

        $this->publishEmailEvent('compose', $thread->id);

        return response()->json(['message' => 'Email sent.', 'thread' => $thread, 'log' => $log]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);
        $validated = $request->validate([
            'body' => 'required|string|max:50000',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
        ]);

        $log = app(EmailComposeService::class)->reply($thread, $validated['body']);

        if (! empty($validated['attachment_ids'])) {
            app(EmailAttachmentService::class)->attach($log, $validated['attachment_ids']);
        }

        $this->publishEmailEvent('reply', $thread->id);

        return response()->json(['message' => 'Reply sent.', 'log' => $log]);
    }

    public function markRead(int $id): JsonResponse
    {
        EmailThread::findOrFail($id)->markAllRead();

        return response()->json(['message' => 'Thread marked as read.']);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);
        $validated = $request->validate(['status' => 'required|in:open,closed,archived']);
        $thread->update(['status' => $validated['status']]);

        return response()->json(['message' => 'Thread status updated.', 'thread' => $thread->fresh()]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:email_threads,id',
            'action' => 'required|string|in:read,unread,star,unstar,trash,spam,inbox,delete,label,unlabel',
            'label' => 'nullable|string|max:50',
        ]);

        app(EmailBulkActionService::class)->apply($validated['ids'], $validated['action'], $validated['label'] ?? null);
        $this->publishEmailEvent('bulk_'.$validated['action'], null);

        return response()->json([
            'message' => "Bulk action '{$validated['action']}' applied to ".count($validated['ids']).' thread(s).',
        ]);
    }

    public function fetchNow(): JsonResponse
    {
        try {
            $fetcher = app(ImapFetcher::class);

            if (! $fetcher->isConfigured()) {
                return response()->json(['message' => 'IMAP not configured.', 'count' => 0], 422);
            }

            $fetcher->connect();
            $count = $fetcher->fetch(20);
            $fetcher->disconnect();

            if ($count > 0) {
                $this->publishEmailEvent('fetch', null);
            }

            return response()->json(['message' => "{$count} email(s) synced.", 'count' => $count]);
        } catch (\Throwable $e) {
            Log::error('[email] fetchNow failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Sync failed: '.$e->getMessage(), 'count' => 0], 500);
        }
    }

    private function enrichThreads($paginator): array
    {
        $threadIds = collect($paginator->items())->pluck('id');
        $lastMessages = EmailLog::whereIn('thread_id', $threadIds)
            ->orderByDesc('created_at')->get()->groupBy('thread_id')
            ->map(fn ($msgs) => $msgs->first());

        return collect($paginator->items())->map(function ($thread) use ($lastMessages) {
            $t = $thread->toArray();
            $last = $lastMessages->get($thread->id);
            $t['last_message'] = $last ? [
                'subject' => $last->subject,
                'body_text' => Str::limit($last->body_text ?? strip_tags($last->body_html ?? ''), 120),
                'direction' => $last->direction,
                'created_at' => $last->created_at?->toISOString(),
            ] : null;

            return $t;
        })->all();
    }

    private function folderCounts(): array
    {
        return [
            'inbox' => EmailThread::folder('inbox')->withUnread()->count(),
            'sent' => EmailLog::where('direction', 'sent')->whereNotNull('thread_id')->distinct('thread_id')->count('thread_id'),
            'draft' => EmailThread::folder('draft')->count(),
            'starred' => EmailThread::starred()->count(),
            'spam' => EmailThread::folder('spam')->count(),
            'trash' => EmailThread::folder('trash')->count(),
        ];
    }

    private function publishEmailEvent(string $action, ?int $threadId): void
    {
        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain('email.updated', null, [
                    'action' => $action,
                    'thread_id' => $threadId,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[email] SSE publish failed (non-blocking)', ['error' => $e->getMessage()]);
        }
    }
}
