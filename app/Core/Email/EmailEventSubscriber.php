<?php

namespace App\Core\Email;

use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

class EmailEventSubscriber
{
    /**
     * Handle message sent event — update EmailLog status to 'sent'.
     */
    public function handleMessageSent(MessageSent $event): void
    {
        $logId = $event->data['emailLogId'] ?? null;
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
     * Handle message sending event — inject custom headers (Message-ID).
     */
    public function handleMessageSending(MessageSending $event): void
    {
        $messageId = $event->data['emailMessageId'] ?? null;
        if ($messageId) {
            $event->message->getHeaders()->addIdHeader('Message-ID', $messageId);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSent::class => 'handleMessageSent',
            MessageSending::class => 'handleMessageSending',
        ];
    }
}
