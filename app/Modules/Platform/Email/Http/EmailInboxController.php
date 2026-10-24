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
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailInboxController
{
    public function index(Request $request): JsonResponse
    {
        $folder = $request->query('folder', 'inbox');
        $starred = $request->boolean('starred');
        $label = $request->query('label');

        $query = EmailThread::with(['company:id,name']);

        // ADR-607 (LOT 5): server-side sort — newest (default), oldest, or unread first.
        match ($request->query('sort', 'date_desc')) {
            'date_asc' => $query->orderBy('last_message_at'),
            'unread_first' => $query->orderByDesc('unread_count')->orderByDesc('last_message_at'),
            default => $query->orderByDesc('last_message_at'),
        };

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

        // ADR-606 (LOT 7): GET is side-effect-free — read state is changed only via the explicit
        // POST /email/inbox/{id}/read endpoint (called by the client when a thread is opened).
        return response()->json(['thread' => $thread, 'messages' => $messages]);
    }

    public function compose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|min:8|max:64',
            'to' => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
            'cc' => ['nullable', 'string', 'max:1000', $this->emailListRule()],
            'bcc' => ['nullable', 'string', 'max:1000', $this->emailListRule()],
            'company_id' => 'nullable|exists:companies,id',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
        ]);

        return $this->idempotent($request, $validated['idempotency_key'], function () use ($validated) {
            [$thread, $log] = app(EmailComposeService::class)->compose($validated);

            if (! empty($validated['attachment_ids'])) {
                app(EmailAttachmentService::class)->attach($log, $validated['attachment_ids']);
            }

            $this->publishEmailEvent('compose', $thread->id);

            // Honest status: queued (SMTP acceptance happens later on the worker → status "sent").
            return ['body' => ['message' => 'Email queued.', 'thread' => $thread, 'log' => $log], 'result' => ['thread_id' => $thread->id, 'log_id' => $log->id]];
        });
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::findOrFail($id);
        $validated = $request->validate([
            'idempotency_key' => 'required|string|min:8|max:64',
            'body' => 'required|string|max:50000',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
        ]);

        return $this->idempotent($request, $validated['idempotency_key'], function () use ($thread, $validated) {
            $log = app(EmailComposeService::class)->reply($thread, $validated['body'], $validated['attachment_ids'] ?? []);

            if (! empty($validated['attachment_ids'])) {
                app(EmailAttachmentService::class)->attach($log, $validated['attachment_ids']);
            }

            $this->publishEmailEvent('reply', $thread->id);

            return ['body' => ['message' => 'Reply queued.', 'log' => $log], 'result' => ['thread_id' => $thread->id, 'log_id' => $log->id]];
        });
    }

    /**
     * ADR-603b (LOT 2 fix): explicit-idempotency-key guard. The client sends a stable key per send
     * attempt (reused on automatic network retries, renewed for a new voluntary send). We reserve
     * the key ATOMICALLY (Cache::add, cross-worker via the shared store) BEFORE any write/send:
     *  - a replay of the SAME key returns the first result (one send only), regardless of content;
     *  - two voluntary sends use different keys → both proceed (even identical content/cc/bcc/files);
     *  - on error the key is RELEASED so a genuine retry is not blocked;
     *  - concurrent duplicates lose the atomic add and get 202 "in progress".
     */
    private function idempotent(Request $request, string $key, Closure $fn): JsonResponse
    {
        $cacheKey = 'email:idem:'.($request->user('platform')?->id ?? 0).':'.$key;

        if (! Cache::add($cacheKey, 'pending', now()->addMinutes(10))) {
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                return response()->json(array_merge(['message' => 'Already processed.', 'idempotent_replay' => true], $stored));
            }

            return response()->json(['message' => 'Send already in progress.', 'duplicate' => true], 202);
        }

        try {
            $out = $fn();
            Cache::put($cacheKey, $out['result'], now()->addMinutes(10));

            return response()->json($out['body']);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey); // release — a legitimate retry must be able to proceed

            throw $e;
        }
    }

    /** ADR-603 (LOT 2): validate a comma/semicolon-separated list of email addresses. */
    private function emailListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (preg_split('/[,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                if (! filter_var(trim($entry), FILTER_VALIDATE_EMAIL)) {
                    $fail("Invalid email address in {$attribute}: ".trim($entry));

                    return;
                }
            }
        };
    }

    public function markRead(int $id): JsonResponse
    {
        EmailThread::findOrFail($id)->markAllRead();

        return response()->json(['message' => 'Thread marked as read.']);
    }

    /**
     * ADR-610 (LOT 1): authoritative unread MESSAGE count for the nav badge.
     * Counts real received messages that are unread AND belong to an inbox-folder thread —
     * i.e. excludes sent messages, Spam and Trash. This is a message count, not a thread count.
     */
    public function unreadCount(): JsonResponse
    {
        $count = EmailLog::where('direction', 'received')
            ->where('is_read', false)
            ->whereHas('thread', fn ($q) => $q->where('folder', 'inbox'))
            ->count();

        return response()->json(['count' => $count]);
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
