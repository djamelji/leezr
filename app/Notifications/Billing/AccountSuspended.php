<?php

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-226: Sent when dunning retries are exhausted and the account is suspended.
 */
class AccountSuspended extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.account_suspended.subject'))
            ->view('emails.billing.account-suspended', [
                'user' => $notifiable,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }
}
