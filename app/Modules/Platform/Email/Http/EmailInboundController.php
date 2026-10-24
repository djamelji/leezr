<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailInboundController
{
    /**
     * Webhook endpoint for inbound emails.
     *
     * ADR-602 (LOT 1) — fail-closed security:
     *  - DISABLED (404) unless a provider secret is configured (`services.email_inbound.secret`).
     *    Current environments have no secret → endpoint is effectively off; IMAP reception is the
     *    live channel and is untouched.
     *  - When enabled: constant-time secret verification (header `X-Inbound-Secret` or `secret`
     *    field) + idempotent handling (replay of the same Message-ID is a no-op, never a 500).
     */
    public function store(Request $request): JsonResponse
    {
        $secret = (string) config('services.email_inbound.secret', '');
        if ($secret === '') {
            abort(404);
        }

        $provided = (string) ($request->header('X-Inbound-Secret') ?? $request->input('secret', ''));
        if (! hash_equals($secret, $provided)) {
            abort(401, 'Invalid inbound secret.');
        }

        $validated = $request->validate([
            'from' => 'required|email',
            'from_name' => 'nullable|string|max:255',
            'to' => 'required|email',
            'subject' => 'required|string|max:500',
            'body_html' => 'nullable|string',
            'body_text' => 'nullable|string',
            'message_id' => 'nullable|string',
            'in_reply_to' => 'nullable|string',
            'references' => 'nullable|string',
        ]);

        // Idempotency / replay guard: a Message-ID already stored is a no-op (never a 500).
        $messageId = ! empty($validated['message_id'])
            ? trim($validated['message_id'], '<>')
            : Str::uuid()->toString().'@inbound.leezr.com';

        $duplicate = EmailLog::where('message_id', $messageId)->first();
        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate ignored.',
                'thread_id' => $duplicate->thread_id,
                'log_id' => $duplicate->id,
            ]);
        }

        try {
            return $this->persist($validated, $messageId);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[email:inbound] failed to persist', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Inbound processing failed.'], 500);
        }
    }

    private function persist(array $validated, string $messageId): JsonResponse
    {
        // Normalize optional keys (absent 'nullable' fields are not present in $validated).
        $validated = array_merge([
            'from_name' => null,
            'body_html' => null,
            'body_text' => null,
            'in_reply_to' => null,
            'references' => null,
        ], $validated);

        // Try to find existing thread via In-Reply-To or References
        $thread = null;
        if ($validated['in_reply_to']) {
            $referencedId = trim($validated['in_reply_to'], '<>');
            $referencedLog = EmailLog::where('message_id', $referencedId)->first();
            if ($referencedLog && $referencedLog->thread_id) {
                $thread = EmailThread::find($referencedLog->thread_id);
            }
        }
        if (! $thread && $validated['references']) {
            // Try each reference
            $refs = preg_split('/\s+/', $validated['references']);
            foreach ($refs as $ref) {
                $refId = trim($ref, '<>');
                $referencedLog = EmailLog::where('message_id', $refId)->first();
                if ($referencedLog && $referencedLog->thread_id) {
                    $thread = EmailThread::find($referencedLog->thread_id);
                    break;
                }
            }
        }

        // Create new thread if not found
        if (! $thread) {
            $thread = EmailThread::create([
                'subject' => $validated['subject'],
                'participant_email' => $validated['from'],
                'participant_name' => $validated['from_name'] ?? null,
                'status' => 'open',
                'last_message_at' => now(),
                'message_count' => 0,
                'unread_count' => 0,
            ]);
        }

        // Create inbound email log
        $log = EmailLog::create([
            'message_id' => $messageId,
            'thread_id' => $thread->id,
            'direction' => 'received',
            'is_read' => false,
            'recipient_email' => $validated['to'],
            'from_email' => $validated['from'],
            'reply_to' => $validated['from'],
            'subject' => $validated['subject'],
            'body_html' => $validated['body_html'],
            'body_text' => $validated['body_text'] ?? strip_tags($validated['body_html'] ?? ''),
            'in_reply_to' => $validated['in_reply_to'] ? trim($validated['in_reply_to'], '<>') : null,
            'template_key' => 'inbound',
            'notification_class' => 'Inbound',
            'status' => 'sent', // received = delivered
            'headers' => [
                'Message-ID' => "<{$messageId}>",
                'In-Reply-To' => $validated['in_reply_to'] ?? null,
                'References' => $validated['references'] ?? null,
            ],
        ]);

        // Update thread counters
        $thread->refreshCounts();

        // Reopen if closed
        if ($thread->status === 'closed') {
            $thread->update(['status' => 'open']);
        }

        return response()->json([
            'message' => 'Inbound email processed.',
            'thread_id' => $thread->id,
            'log_id' => $log->id,
        ]);
    }
}
