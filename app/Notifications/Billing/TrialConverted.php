<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-286: Sent when a trial subscription converts to active (first billing).
 */
class TrialConverted extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly Subscription $subscription,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.trial_converted.subject'))
            ->view('emails.billing.trial-converted', [
                'user' => $notifiable,
                'subscription' => $this->subscription,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
