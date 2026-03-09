<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-286: Sent when a trial subscription converts to active (first billing).
 */
class TrialConverted extends Notification
{
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
            ->subject('Your trial has ended — subscription is now active')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Your free trial has ended and your subscription is now **active**.')
            ->line('Your first invoice has been generated and billing will continue according to your plan.')
            ->action('View Billing', url('/company/billing'))
            ->line('Have questions? Contact our support team.');
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
