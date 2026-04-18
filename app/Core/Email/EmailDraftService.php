<?php

namespace App\Core\Email;

class EmailDraftService
{
    /**
     * Save or update a draft.
     */
    public function persist(array $data): array
    {
        $draftId = $data['draft_id'] ?? null;

        if ($draftId) {
            // Update existing draft
            $log = EmailLog::where('id', $draftId)->where('is_draft', true)->firstOrFail();
            $thread = $log->thread;

            $log->update([
                'recipient_email' => $data['to'] ?? $log->recipient_email,
                'subject' => $data['subject'] ?? $log->subject,
                'body_html' => $data['body'] ?? $log->body_html,
                'body_text' => strip_tags($data['body'] ?? $log->body_html ?? ''),
                'cc' => $data['cc'] ?? null,
                'bcc' => $data['bcc'] ?? null,
            ]);

            $thread->update([
                'subject' => $data['subject'] ?? $thread->subject,
                'participant_email' => $data['to'] ?? $thread->participant_email,
            ]);

            return ['thread' => $thread->fresh(), 'log' => $log->fresh(), 'draft_id' => $log->id];
        }

        // Create new draft
        $thread = EmailThread::create([
            'subject' => $data['subject'] ?? '(Brouillon)',
            'participant_email' => $data['to'] ?? '',
            'status' => 'open',
            'folder' => 'draft',
            'last_message_at' => now(),
            'message_count' => 1,
            'unread_count' => 0,
        ]);

        $log = EmailLog::create([
            'message_id' => \Illuminate\Support\Str::uuid()->toString().'@draft',
            'recipient_email' => $data['to'] ?? '',
            'from_email' => config('mail.from.address', 'noreply@leezr.com'),
            'subject' => $data['subject'] ?? '',
            'body_html' => $data['body'] ?? '',
            'body_text' => strip_tags($data['body'] ?? ''),
            'cc' => $data['cc'] ?? null,
            'bcc' => $data['bcc'] ?? null,
            'template_key' => 'draft',
            'notification_class' => 'Draft',
            'status' => 'queued',
            'direction' => 'sent',
            'thread_id' => $thread->id,
            'is_read' => true,
            'is_draft' => true,
        ]);

        return ['thread' => $thread, 'log' => $log, 'draft_id' => $log->id];
    }

    /**
     * Send a draft — transforms it into a real email.
     */
    public function send(int $draftId, array $data): array
    {
        $log = EmailLog::where('id', $draftId)->where('is_draft', true)->firstOrFail();
        $thread = $log->thread;

        // Delete the draft log + thread
        $log->delete();
        $thread->delete();

        // Use the compose service to send the actual email
        return app(EmailComposeService::class)->compose(array_merge($data, [
            'to_name' => null,
        ]));
    }

    /**
     * Delete a draft.
     */
    public function delete(int $draftId): void
    {
        $log = EmailLog::where('id', $draftId)->where('is_draft', true)->firstOrFail();
        $thread = $log->thread;

        $log->delete();
        if ($thread) {
            $thread->delete();
        }
    }
}
