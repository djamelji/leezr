<?php

namespace App\Core\Email;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImapFetcher
{
    private $connection;
    private array $settings;

    public function __construct()
    {
        $this->settings = PlatformSetting::first()?->email ?? [];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->settings['imap_host'])
            && ! empty($this->settings['imap_username'])
            && ! empty($this->settings['imap_password']);
    }

    public function connect(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $host = $this->settings['imap_host'];
        $port = (int) ($this->settings['imap_port'] ?? 993);
        $encryption = $this->settings['imap_encryption'] ?? 'ssl';
        $username = $this->settings['imap_username'];
        $password = $this->settings['imap_password'];
        $folder = $this->settings['imap_folder'] ?? 'INBOX';

        $flags = $encryption === 'ssl' ? '/imap/ssl' : '/imap';
        // Accept self-signed certs on ISPConfig
        $flags .= '/novalidate-cert';

        $mailbox = "{{$host}:{$port}{$flags}}{$folder}";

        try {
            $this->connection = imap_open($mailbox, $username, $password, 0, 1);

            if (! $this->connection) {
                Log::error('[imap] Connection failed', ['error' => imap_last_error()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[imap] Connection error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Fetch new emails and store them as EmailLog entries.
     * Returns the count of new emails fetched.
     */
    public function fetch(int $limit = 50): int
    {
        if (! $this->connection) {
            return 0;
        }

        $host = $this->settings['imap_host'];
        $port = (int) ($this->settings['imap_port'] ?? 993);
        $encryption = $this->settings['imap_encryption'] ?? 'ssl';
        $flags = $encryption === 'ssl' ? '/imap/ssl' : '/imap';
        $flags .= '/novalidate-cert';
        $mailboxBase = "{{$host}:{$port}{$flags}}";

        // Fetch from INBOX + Junk (external mails often land in Junk)
        $folders = ['INBOX', 'Junk'];

        // Find the last fetched email's date to only get newer ones
        $lastFetched = EmailLog::where('direction', 'received')
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->first();

        $since = $lastFetched
            ? $lastFetched->sent_at->subHour()->format('d-M-Y')
            : now()->subDays(30)->format('d-M-Y');

        $fetched = 0;
        $account = (string) ($this->settings['imap_username'] ?? '');

        foreach ($folders as $folder) {
            // Reopen connection for each folder
            if ($folder !== 'INBOX') {
                $reopened = @imap_reopen($this->connection, $mailboxBase.$folder);
                if (! $reopened) {
                    Log::info("[imap] Folder {$folder} not available, skipping");

                    continue;
                }
            }

            // UIDVALIDITY — part of the persistent message identity (ADR-740). If the
            // server resets it, UIDs are renumbered; a new uidvalidity yields new
            // identities, so messages re-import once under the new key (bounded, safe).
            $status = @imap_status($this->connection, $mailboxBase.$folder, SA_UIDVALIDITY);
            $uidValidity = (int) ($status->uidvalidity ?? 0);

            $messageNums = @imap_search($this->connection, "SINCE \"{$since}\"", SE_UID);

            if (! $messageNums) {
                continue;
            }

            // Take latest N messages per folder
            $messageNums = array_slice(array_reverse($messageNums), 0, $limit);

            foreach ($messageNums as $uid) {
                try {
                    if ($this->processMessage((int) $uid, $folder, $uidValidity, $account)) {
                        $fetched++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[imap] Failed to process message', [
                        'uid' => $uid,
                        'folder' => $folder,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Switch back to INBOX
        @imap_reopen($this->connection, $mailboxBase.'INBOX');

        return $fetched;
    }

    private function processMessage(int $uid, string $imapFolder, int $uidValidity, string $account): bool
    {
        $headers = imap_fetchheader($this->connection, $uid, FT_UID);
        $headerObj = imap_rfc822_parse_headers($headers);

        $messageId = isset($headerObj->message_id) ? trim($headerObj->message_id, '<>') : null;
        $messageId = ($messageId === '') ? null : $messageId;

        // Parse sender
        $from = $headerObj->from[0] ?? null;
        $fromEmail = $from ? "{$from->mailbox}@{$from->host}" : 'unknown@unknown.com';
        $fromName = isset($from->personal) ? $this->decodeMimeHeader($from->personal) : null;

        // Parse recipient
        $to = $headerObj->to[0] ?? null;
        $toEmail = $to ? "{$to->mailbox}@{$to->host}" : ($this->settings['imap_username'] ?? '');

        // Parse subject (RFC 2047 → clean UTF-8)
        $subject = isset($headerObj->subject) ? $this->decodeMimeHeader($headerObj->subject) : '(no subject)';

        // Parse date
        $date = isset($headerObj->date) ? \Carbon\Carbon::parse($headerObj->date) : now();

        // Parse In-Reply-To and References for threading
        $inReplyTo = isset($headerObj->in_reply_to) ? trim($headerObj->in_reply_to, '<>') : null;
        $references = $headerObj->references ?? null;

        // Get body
        $body = $this->getBody($uid);

        // Map IMAP folder to app folder: Junk → spam, default → inbox
        $appFolder = match ($imapFolder) {
            'Junk' => 'spam',
            'Trash' => 'trash',
            default => 'inbox',
        };

        $log = $this->storeMessage([
            'account' => $account,
            'uidvalidity' => $uidValidity,
            'uid' => $uid,
            'imap_folder' => $imapFolder,
            'app_folder' => $appFolder,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_html' => $body['html'],
            'body_text' => $body['text'],
            'date' => $date,
        ]);

        if ($log === null) {
            return false; // already imported (idempotent) or lost a concurrency race
        }

        // Attachments + counters + SSE run AFTER the atomic thread+log commit.
        $this->extractAttachments($uid, $log);

        if ($log->thread) {
            $log->thread->refreshCounts();
        }

        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain('email.updated', null, [
                    'action' => 'new_message',
                    'thread_id' => $log->thread_id,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[imap] SSE publish failed (non-blocking)', ['error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * Persist a parsed inbound message as (thread + log) ATOMICALLY and IDEMPOTENTLY.
     * Returns the created EmailLog, or null if the message was already imported.
     *
     * ADR-740 — the identity is (imap_account, uidvalidity, folder, uid), NOT the
     * Message-ID (which may be absent, or shared across accounts). The thread+log are
     * created in a transaction so a failing log insert never leaves an orphan thread.
     * Pure DB + string work → unit-testable without an IMAP connection.
     */
    public function storeMessage(array $m): ?EmailLog
    {
        $account = (string) ($m['account'] ?? '');
        $uidValidity = (int) ($m['uidvalidity'] ?? 0);
        $uid = (int) ($m['uid'] ?? 0);
        $imapFolder = (string) ($m['imap_folder'] ?? 'INBOX');
        $realMessageId = ($m['message_id'] ?? null) ?: null;

        // Deterministic stored id — NEVER random. Real Message-ID if present, else derived
        // from the persistent IMAP identity so re-processing the same message dedups.
        $storedMessageId = $realMessageId
            ?? 'imap-'.sha1($account.'|'.$uidValidity.'|'.$imapFolder.'|'.$uid).'@imap.inbound';

        // 1. Fast idempotence — already imported under this IMAP identity?
        if (EmailLog::query()
            ->where('imap_account', $account)
            ->where('imap_uidvalidity', $uidValidity)
            ->where('imap_folder', $imapFolder)
            ->where('imap_uid', $uid)
            ->exists()
        ) {
            return null;
        }

        // 2. UIDVALIDITY-change safety — same real Message-ID already stored for this
        //    account (prevents re-duplicating everything when the server renumbers UIDs).
        if ($realMessageId !== null
            && EmailLog::query()
                ->where('imap_account', $account)
                ->where('message_id', $realMessageId)
                ->exists()
        ) {
            return null;
        }

        $subject = $this->sanitizeUtf8((string) ($m['subject'] ?? '')) ?: '(no subject)';
        $fromName = $this->sanitizeUtf8($m['from_name'] ?? null);
        $bodyHtml = $this->sanitizeUtf8($m['body_html'] ?? null);
        $bodyText = $this->sanitizeUtf8($m['body_text'] ?? null);

        try {
            return DB::transaction(function () use ($m, $account, $uidValidity, $uid, $imapFolder, $storedMessageId, $realMessageId, $subject, $fromName, $bodyHtml, $bodyText) {
                $thread = $this->findOrCreateThread(
                    (string) ($m['from_email'] ?? 'unknown@unknown.com'),
                    $fromName,
                    $subject,
                    $m['in_reply_to'] ?? null,
                    $m['references'] ?? null,
                    (string) ($m['app_folder'] ?? 'inbox')
                );

                if ($thread->status === 'closed') {
                    $thread->update(['status' => 'open']);
                }

                return EmailLog::create([
                    'message_id' => $storedMessageId,
                    'in_reply_to' => $m['in_reply_to'] ?? null,
                    'imap_account' => $account,
                    'imap_uidvalidity' => $uidValidity,
                    'imap_uid' => $uid,
                    'imap_folder' => $imapFolder,
                    'company_id' => $thread->company_id,
                    'recipient_email' => (string) ($m['to_email'] ?? ''),
                    'recipient_name' => null,
                    'from_email' => (string) ($m['from_email'] ?? 'unknown@unknown.com'),
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                    'template_key' => 'inbound',
                    'notification_class' => 'ImapFetch',
                    'status' => 'sent',
                    'direction' => 'received',
                    'thread_id' => $thread->id,
                    'is_read' => false,
                    'headers' => [
                        'Message-ID' => $realMessageId ? "<{$realMessageId}>" : null,
                        'In-Reply-To' => ($m['in_reply_to'] ?? null) ? "<{$m['in_reply_to']}>" : null,
                        'References' => $m['references'] ?? null,
                    ],
                    'metadata' => ['source' => 'imap', 'imap_folder' => $imapFolder],
                    'sent_at' => $m['date'] ?? now(),
                ]);
            });
        } catch (QueryException $e) {
            // A concurrent import won the race on uq_email_logs_imap_identity → safe no-op.
            // The transaction already rolled back this attempt's thread (no orphan).
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ((int) ($e->errorInfo[1] ?? 0)) === 1062   // MySQL duplicate entry
            || (string) $e->getCode() === '23000';         // SQLSTATE integrity constraint
    }

    /**
     * Decode an RFC 2047 MIME header to clean UTF-8 (Shift-JIS, ISO-2022-JP, …).
     */
    private function decodeMimeHeader(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        $decoded = '';
        foreach (imap_mime_header_decode($raw) as $part) {
            $charset = strtoupper($part->charset ?? 'default');
            $text = $part->text ?? '';
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
                $conv = @mb_convert_encoding($text, 'UTF-8', $charset);
                $text = ($conv !== false && $conv !== null && $conv !== '') ? $conv : $text;
            }
            $decoded .= $text;
        }

        return $this->sanitizeUtf8($decoded);
    }

    /**
     * Guarantee a valid UTF-8 string — prevents MySQL "1366 Incorrect string value".
     */
    private function sanitizeUtf8(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        if (! mb_check_encoding($s, 'UTF-8')) {
            $enc = mb_detect_encoding($s, ['UTF-8', 'ISO-2022-JP', 'SJIS-win', 'SJIS', 'EUC-JP', 'Windows-1252', 'ISO-8859-1'], true);
            if ($enc && $enc !== 'UTF-8') {
                $conv = @mb_convert_encoding($s, 'UTF-8', $enc);
                if ($conv !== false && $conv !== null) {
                    $s = $conv;
                }
            }
        }

        // Final safety net — drop any residual invalid byte sequences.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($clean === false) {
            $clean = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        return $clean;
    }

    private function getBody(int $uid): array
    {
        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        $html = null;
        $text = null;

        if (empty($structure->parts)) {
            // Simple message (no multipart)
            $body = imap_body($this->connection, $uid, FT_UID);
            $body = $this->decodeBody($body, $structure->encoding ?? 0);
            $body = $this->decodeCharset($body, $this->getCharset($structure));

            if (($structure->subtype ?? '') === 'HTML') {
                $html = $body;
                $text = strip_tags($body);
            } else {
                $text = $body;
            }
        } else {
            // Multipart — find text/plain and text/html
            foreach ($structure->parts as $partIndex => $part) {
                $partBody = imap_fetchbody($this->connection, $uid, (string) ($partIndex + 1), FT_UID);
                $partBody = $this->decodeBody($partBody, $part->encoding ?? 0);
                $partBody = $this->decodeCharset($partBody, $this->getCharset($part));

                $subtype = strtoupper($part->subtype ?? '');
                if ($subtype === 'PLAIN' && $text === null) {
                    $text = $partBody;
                } elseif ($subtype === 'HTML' && $html === null) {
                    $html = $partBody;
                }

                // Check sub-parts (nested multipart)
                if (! empty($part->parts)) {
                    foreach ($part->parts as $subIndex => $subPart) {
                        $subBody = imap_fetchbody($this->connection, $uid, ($partIndex + 1).'.'.($subIndex + 1), FT_UID);
                        $subBody = $this->decodeBody($subBody, $subPart->encoding ?? 0);
                        $subBody = $this->decodeCharset($subBody, $this->getCharset($subPart));

                        $subType = strtoupper($subPart->subtype ?? '');
                        if ($subType === 'PLAIN' && $text === null) {
                            $text = $subBody;
                        } elseif ($subType === 'HTML' && $html === null) {
                            $html = $subBody;
                        }
                    }
                }
            }
        }

        if ($text === null && $html !== null) {
            $text = strip_tags($html);
        }

        return ['html' => $html, 'text' => $text];
    }

    private function extractAttachments(int $uid, EmailLog $log): void
    {
        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        if (empty($structure->parts)) {
            return;
        }

        $attachmentService = app(EmailAttachmentService::class);

        foreach ($structure->parts as $partIndex => $part) {
            $disposition = strtoupper($part->disposition ?? '');
            if ($disposition !== 'ATTACHMENT' && $disposition !== 'INLINE') {
                continue;
            }

            // Get filename
            $filename = 'attachment';
            if (! empty($part->dparameters)) {
                foreach ($part->dparameters as $param) {
                    if (strtoupper($param->attribute) === 'FILENAME') {
                        $filename = imap_utf8($param->value);
                        break;
                    }
                }
            }
            if ($filename === 'attachment' && ! empty($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (strtoupper($param->attribute) === 'NAME') {
                        $filename = imap_utf8($param->value);
                        break;
                    }
                }
            }

            $content = imap_fetchbody($this->connection, $uid, (string) ($partIndex + 1), FT_UID);
            $content = $this->decodeBody($content, $part->encoding ?? 0);

            $mimeType = strtolower(($part->subtype ?? 'OCTET-STREAM'));
            $primaryType = match ($part->type ?? 0) {
                0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
                4 => 'audio', 5 => 'image', 6 => 'video',
                default => 'application',
            };
            $fullMime = "{$primaryType}/{$mimeType}";

            try {
                $attachmentService->storeFromImap($log, $filename, $content, $fullMime);
            } catch (\Throwable $e) {
                Log::warning('[imap] Failed to store attachment', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body),             // BASE64
            4 => quoted_printable_decode($body),   // QUOTED-PRINTABLE
            default => $body,
        };
    }

    private function getCharset(object $structure): string
    {
        if (! empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtoupper($param->attribute) === 'CHARSET') {
                    return strtoupper($param->value);
                }
            }
        }

        return 'UTF-8';
    }

    private function decodeCharset(string $body, string $charset): string
    {
        if ($charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $body;
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);

        return $converted !== false ? $converted : $body;
    }

    private function findOrCreateThread(string $fromEmail, ?string $fromName, string $subject, ?string $inReplyTo, ?string $references, string $folder = 'inbox'): EmailThread
    {
        // 1. Try to match by In-Reply-To → find existing log → get thread
        if ($inReplyTo) {
            $referencedLog = EmailLog::where('message_id', $inReplyTo)->first();
            if ($referencedLog && $referencedLog->thread_id) {
                return $this->resurrectToInbox(EmailThread::find($referencedLog->thread_id), $folder);
            }
        }

        // 2. Try to match by References
        if ($references) {
            $refIds = preg_match_all('/<([^>]+)>/', $references, $matches)
                ? $matches[1] : [];

            foreach ($refIds as $refId) {
                $referencedLog = EmailLog::where('message_id', $refId)->first();
                if ($referencedLog && $referencedLog->thread_id) {
                    return $this->resurrectToInbox(EmailThread::find($referencedLog->thread_id), $folder);
                }
            }
        }

        // 3. Fallback match by participant + normalized subject — ADR-602 (LOT 1): ONLY when the
        //    incoming message is itself a reply/forward (Re:/Fwd:/Fw: prefix) AND lands in a recent
        //    (≤30d) open thread. This preserves real conversations whose clients omit References,
        //    while preventing independent same-sender/same-subject blasts (e.g. bounce spam) from
        //    collapsing into a single giant thread.
        $normalizedSubject = preg_replace('/^(Re:\s*|Fwd:\s*|Fw:\s*)+/i', '', $subject);
        $isReplyOrForward = (bool) preg_match('/^\s*(Re|Fwd|Fw)\s*:/i', $subject);

        if ($isReplyOrForward) {
            $existing = EmailThread::where('participant_email', $fromEmail)
                ->where(function ($q) use ($normalizedSubject, $subject) {
                    $q->where('subject', $subject)
                        ->orWhere('subject', $normalizedSubject);
                })
                ->where('status', '!=', 'archived')
                ->where('last_message_at', '>=', now()->subDays(30))
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing) {
                return $this->resurrectToInbox($existing, $folder);
            }
        }

        // 4. Create new thread
        return EmailThread::create([
            'subject' => $normalizedSubject,
            'participant_email' => $fromEmail,
            'participant_name' => $fromName,
            'folder' => $folder,
            'status' => 'open',
            'last_message_at' => now(),
            'message_count' => 0,
            'unread_count' => 0,
        ]);
    }

    /**
     * ADR-612: a new INBOUND message arriving from the server INBOX resurrects a conversation that
     * was trashed / sent / drafted back into the Inbox — standard mail behaviour (a fresh reply to a
     * trashed thread reappears). Without this, replies attaching to an old non-inbox thread inherit
     * its folder and never surface in the Inbox. Spam stays spam (server-flagged); Junk arrives as
     * 'spam' and is not resurrected.
     */
    private function resurrectToInbox(EmailThread $thread, string $incomingFolder): EmailThread
    {
        if ($incomingFolder === 'inbox' && in_array($thread->folder, ['trash', 'sent', 'draft'], true)) {
            $thread->update(['folder' => 'inbox']);
        }

        return $thread;
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
