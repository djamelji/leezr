<?php

namespace App\Core\Email;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ADR-848 (Leezr 4.2 M9) — read side of the Platform inbox, moved verbatim from EmailInboxController : thread list
 * (folder / starred / sent / label / status / unread / company / search, ADR-607 sort), last message preview,
 * folder counts, stats, and the authoritative unread MESSAGE count of the nav badge (ADR-610).
 */
class EmailInboxReadModel
{
    public static function list(Request $request): array
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

        return [
            'data' => self::enrichThreads($threads),
            'current_page' => $threads->currentPage(),
            'last_page' => $threads->lastPage(),
            'total' => $threads->total(),
            'folder_counts' => self::folderCounts(),
            'stats' => [
                'total_unread' => EmailThread::where('unread_count', '>', 0)->count(),
                'open' => EmailThread::open()->count(),
                'closed' => EmailThread::closed()->count(),
            ],
        ];
    }

    /** ADR-610 (LOT 1): received + unread messages of inbox-folder threads (sent, Spam and Trash excluded). */
    public static function unreadMessages(): int
    {
        return EmailLog::where('direction', 'received')
            ->where('is_read', false)
            ->whereHas('thread', fn ($q) => $q->where('folder', 'inbox'))
            ->count();
    }

    private static function enrichThreads($paginator): array
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

    private static function folderCounts(): array
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
}
