<?php

namespace App\Core\Email;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // Find the last fetched email's date to only get newer ones
        $lastFetched = EmailLog::where('direction', 'received')
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->first();

        // Search for emails since last fetch (or last 7 days if first run)
        $since = $lastFetched
            ? $lastFetched->sent_at->subHour()->format('d-M-Y')
            : now()->subDays(7)->format('d-M-Y');

        $messageNums = imap_search($this->connection, "SINCE \"{$since}\"", SE_UID);

        if (! $messageNums) {
            return 0;
        }

        // Take latest N messages
        $messageNums = array_slice(array_reverse($messageNums), 0, $limit);

        $fetched = 0;
        foreach ($messageNums as $uid) {
            try {
                if ($this->processMessage($uid)) {
                    $fetched++;
                }
            } catch (\Throwable $e) {
                Log::warning('[imap] Failed to process message', [
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $fetched;
    }

    private function processMessage(int $uid): bool
    {
        $headers = imap_fetchheader($this->connection, $uid, FT_UID);
        $headerObj = imap_rfc822_parse_headers($headers);

        $messageId = isset($headerObj->message_id)
            ? trim($headerObj->message_id, '<>')
            : null;

        // Skip if already fetched (dedup by message_id)
        if ($messageId && EmailLog::where('message_id', $messageId)->exists()) {
            return false;
        }

        // Parse sender
        $from = $headerObj->from[0] ?? null;
        $fromEmail = $from ? "{$from->mailbox}@{$from->host}" : 'unknown@unknown.com';
        $fromName = isset($from->personal) ? imap_utf8($from->personal) : null;

        // Parse recipient
        $to = $headerObj->to[0] ?? null;
        $toEmail = $to ? "{$to->mailbox}@{$to->host}" : ($this->settings['imap_username'] ?? '');

        // Parse subject
        $subject = isset($headerObj->subject) ? imap_utf8($headerObj->subject) : '(no subject)';

        // Parse date
        $date = isset($headerObj->date) ? \Carbon\Carbon::parse($headerObj->date) : now();

        // Parse In-Reply-To and References for threading
        $inReplyTo = isset($headerObj->in_reply_to)
            ? trim($headerObj->in_reply_to, '<>')
            : null;

        $references = isset($headerObj->references)
            ? $headerObj->references
            : null;

        // Get body and attachments
        $body = $this->getBody($uid);

        // Find or create thread
        $thread = $this->findOrCreateThread($fromEmail, $fromName, $subject, $inReplyTo, $references);

        // Create EmailLog
        $log = EmailLog::create([
            'message_id' => $messageId ?? Str::uuid()->toString().'@imap-fetched',
            'in_reply_to' => $inReplyTo,
            'company_id' => $thread->company_id,
            'recipient_email' => $toEmail,
            'recipient_name' => null,
            'from_email' => $fromEmail,
            'subject' => $subject,
            'body_html' => $body['html'],
            'body_text' => $body['text'],
            'template_key' => 'inbound',
            'notification_class' => 'ImapFetch',
            'status' => 'sent',
            'direction' => 'received',
            'thread_id' => $thread->id,
            'is_read' => false,
            'headers' => [
                'Message-ID' => $messageId ? "<{$messageId}>" : null,
                'In-Reply-To' => $inReplyTo ? "<{$inReplyTo}>" : null,
                'References' => $references,
            ],
            'metadata' => ['source' => 'imap'],
            'sent_at' => $date,
        ]);

        // Extract attachments
        $this->extractAttachments($uid, $log);

        // Update thread counters
        $thread->refreshCounts();

        // Reopen thread if closed
        if ($thread->status === 'closed') {
            $thread->update(['status' => 'open']);
        }

        // Emit SSE event for real-time inbox updates (ADR-453)
        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain('email.updated', null, [
                    'action' => 'new_message',
                    'thread_id' => $thread->id,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[imap] SSE publish failed (non-blocking)', ['error' => $e->getMessage()]);
        }

        return true;
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

    private function findOrCreateThread(string $fromEmail, ?string $fromName, string $subject, ?string $inReplyTo, ?string $references): EmailThread
    {
        // 1. Try to match by In-Reply-To → find existing log → get thread
        if ($inReplyTo) {
            $referencedLog = EmailLog::where('message_id', $inReplyTo)->first();
            if ($referencedLog && $referencedLog->thread_id) {
                return EmailThread::find($referencedLog->thread_id);
            }
        }

        // 2. Try to match by References
        if ($references) {
            $refIds = preg_match_all('/<([^>]+)>/', $references, $matches)
                ? $matches[1] : [];

            foreach ($refIds as $refId) {
                $referencedLog = EmailLog::where('message_id', $refId)->first();
                if ($referencedLog && $referencedLog->thread_id) {
                    return EmailThread::find($referencedLog->thread_id);
                }
            }
        }

        // 3. Try to match by participant email + normalized subject
        $normalizedSubject = preg_replace('/^(Re:\s*|Fwd:\s*|Fw:\s*)+/i', '', $subject);
        $existing = EmailThread::where('participant_email', $fromEmail)
            ->where(function ($q) use ($normalizedSubject, $subject) {
                $q->where('subject', $subject)
                    ->orWhere('subject', $normalizedSubject);
            })
            ->where('status', '!=', 'archived')
            ->orderByDesc('last_message_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        // 4. Create new thread
        return EmailThread::create([
            'subject' => $normalizedSubject,
            'participant_email' => $fromEmail,
            'participant_name' => $fromName,
            'status' => 'open',
            'last_message_at' => now(),
            'message_count' => 0,
            'unread_count' => 0,
        ]);
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
