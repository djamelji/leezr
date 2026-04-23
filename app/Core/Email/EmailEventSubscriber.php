<?php

namespace App\Core\Email;

use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Config;

class EmailEventSubscriber
{
    /**
     * Handle message sent event — update EmailLog status to 'sent'.
     */
    public function handleMessageSent(MessageSent $event): void
    {
        $logId = $this->extractLogId($event->data);
        if (! $logId) {
            return;
        }

        $log = EmailLog::find($logId);
        if (! $log) {
            return;
        }

        // Extract external message ID from SMTP response if available
        $externalId = $event->sent?->getMessageId() ?? null;

        $log->markSent($externalId);
    }

    /**
     * Handle message sending event — inject custom headers for deliverability.
     *
     * - Auto-configures SMTP for queue workers (ADR-461)
     * - Sets Message-ID for tracking
     * - NO Return-Path override (let Postfix use From address — like Roundcube)
     * - NO List-Unsubscribe (non-existing mailbox is worse than no header)
     * - NO X-Mailer (non-standard header can trigger spam filters)
     */
    public function handleMessageSending(MessageSending $event): void
    {
        // Auto-configure SMTP for queue workers and any non-dynamic mailer context.
        if (Config::get('mail.default') !== 'dynamic') {
            app(EmailService::class)->configureSmtp();
        }

        $headers = $event->message->getHeaders();
        $messageId = $event->data['emailMessageId'] ?? null;

        if ($messageId) {
            $headers->addIdHeader('Message-ID', $messageId);
        }
    }

    /**
     * Extract emailLogId from event data (passed via MailMessage view data).
     */
    private function extractLogId(array $data): ?int
    {
        return ! empty($data['emailLogId']) ? (int) $data['emailLogId'] : null;
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSent::class => 'handleMessageSent',
            MessageSending::class => 'handleMessageSending',
        ];
    }
}
