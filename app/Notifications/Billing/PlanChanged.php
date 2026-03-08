<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a company's plan is changed (upgrade or downgrade).
 */
class PlanChanged extends Notification
{
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
            ->subject("Plan changed to {$this->newPlanName}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your plan has been changed from **{$this->oldPlanName}** to **{$this->newPlanName}**.")
            ->action('View Plan', url('/company/plan'))
            ->line('If you have questions about your new plan, please contact our support team.');
    }
}
