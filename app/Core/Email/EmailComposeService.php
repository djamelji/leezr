<?php

namespace App\Core\Email;

use App\Core\Models\Company;
use App\Notifications\Email\ManualEmailNotification;

class EmailComposeService
{
    /**
     * Send a new email and create a thread.
     *
     * @return array{0: EmailThread, 1: EmailLog}
     */
    public function compose(array $data): array
    {
        $company = isset($data['company_id']) ? Company::find($data['company_id']) : null;

        $thread = EmailThread::create([
            'subject' => $data['subject'],
            'company_id' => $company?->id,
            'participant_email' => $data['to'],
            'participant_name' => $data['to_name'] ?? null,
            'status' => 'open',
            'folder' => 'sent',
            'last_message_at' => now(),
            'message_count' => 1,
            'unread_count' => 0,
        ]);

        $recipient = new EmailRecipient($data['to'], $data['to_name'] ?? null);
        $notification = new ManualEmailNotification($data['subject'], $data['body']);
        $notification->cc = $data['cc'] ?? null;
        $notification->bcc = $data['bcc'] ?? null;

        $log = app(EmailService::class)->send($notification, $recipient, 'manual.compose', $company, [
            'thread_id' => $thread->id,
            'manual' => true,
        ]);

        $log->update([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'is_read' => true,
            'body_html' => $data['body'],
            'body_text' => strip_tags($data['body']),
            'cc' => $data['cc'] ?? null,
            'bcc' => $data['bcc'] ?? null,
        ]);

        // Auto-extract contacts (non-blocking — must never break email sending)
        try {
            EmailContact::recordUsage($data['to'], $data['to_name'] ?? null);
            $this->recordCcBccContacts($data['cc'] ?? null, $data['bcc'] ?? null);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[email] Contact extraction failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        return [$thread->fresh(), $log];
    }

    /**
     * Reply to an existing thread.
     */
    public function reply(EmailThread $thread, string $body): EmailLog
    {
        $lastMessage = EmailLog::where('thread_id', $thread->id)
            ->orderByDesc('created_at')
            ->first();

        $recipient = new EmailRecipient($thread->participant_email, $thread->participant_name);
        $notification = new ManualEmailNotification("Re: {$thread->subject}", $body);

        $log = app(EmailService::class)->send($notification, $recipient, 'manual.reply', $thread->company, [
            'thread_id' => $thread->id,
            'in_reply_to' => $lastMessage?->message_id,
            'manual' => true,
        ]);

        $log->update([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'is_read' => true,
            'in_reply_to' => $lastMessage?->message_id,
            'body_html' => $body,
            'body_text' => strip_tags($body),
            'headers' => array_merge($log->headers ?? [], [
                'In-Reply-To' => $lastMessage ? "<{$lastMessage->message_id}>" : null,
                'References' => $lastMessage ? "<{$lastMessage->message_id}>" : null,
            ]),
        ]);

        $thread->update([
            'last_message_at' => now(),
            'message_count' => $thread->messages()->count(),
        ]);

        // Auto-extract contact from reply (non-blocking)
        try {
            EmailContact::recordUsage($thread->participant_email, $thread->participant_name);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[email] Contact extraction failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    private function recordCcBccContacts(?string $cc, ?string $bcc): void
    {
        foreach ([$cc, $bcc] as $field) {
            if (! $field) {
                continue;
            }
            foreach (array_filter(array_map('trim', explode(',', $field))) as $email) {
                EmailContact::recordUsage($email);
            }
        }
    }
}
