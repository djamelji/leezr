<?php

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a company's plan is changed (upgrade or downgrade).
 */
class PlanChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly string $oldPlanName,
        private readonly string $newPlanName,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.plan_changed.subject', ['plan' => $this->newPlanName]))
            ->view('emails.billing.plan-changed', [
                'user' => $notifiable,
                'oldPlanName' => $this->oldPlanName,
                'newPlanName' => $this->newPlanName,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }
}
