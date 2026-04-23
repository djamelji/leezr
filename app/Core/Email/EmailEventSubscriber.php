<?php

namespace App\Core\Email;

use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

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
     * - Headers: Message-ID, Return-Path (SPF alignment), List-Unsubscribe, X-Mailer
     */
    public function handleMessageSending(MessageSending $event): void
    {
        // Auto-configure SMTP for queue workers and any non-dynamic mailer context.
        if (Config::get('mail.default') !== 'dynamic') {
            app(EmailService::class)->configureSmtp();
        }

        $headers = $event->message->getHeaders();
        $notification = $event->data['__notification'] ?? null;
        $messageId = $event->data['emailMessageId'] ?? $notification?->emailMessageId ?? null;

        if ($messageId) {
            $headers->addIdHeader('Message-ID', $messageId);
        }

        // Return-Path aligned with SPF domain for DMARC alignment
        $fromEmail = $event->message->getFrom()[0]?->getAddress() ?? null;
        if ($fromEmail) {
            $domain = substr($fromEmail, strpos($fromEmail, '@') + 1) ?: 'leezr.com';
            $event->message->returnPath("noreply@{$domain}");

            // List-Unsubscribe — improves Gmail/Outlook reputation scoring
            if (! $headers->has('List-Unsubscribe')) {
                $headers->addTextHeader('List-Unsubscribe', "<mailto:unsubscribe@{$domain}>");
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        }

        // X-Mailer identification
        if (! $headers->has('X-Mailer')) {
            $headers->addTextHeader('X-Mailer', 'Leezr/1.0');
        }
    }

    /**
     * Extract emailLogId from event data — supports both direct key
     * and queued notification property (__notification->emailLogId).
     */
    private function extractLogId(array $data): ?int
    {
        if (! empty($data['emailLogId'])) {
            return (int) $data['emailLogId'];
        }

        $notification = $data['__notification'] ?? null;

        return $notification?->emailLogId ?? null;
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSent::class => 'handleMessageSent',
            MessageSending::class => 'handleMessageSending',
        ];
    }
}
