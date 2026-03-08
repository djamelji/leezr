<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent 3 days before a trial subscription expires.
 */
class TrialExpiring extends Notification
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
        $endsAt = $this->subscription->trial_ends_at->format('M j, Y');

        return (new MailMessage)
            ->subject('Your trial ends on '.$endsAt)
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your free trial will end on **{$endsAt}**.")
            ->line('To continue using all features, please add a payment method before your trial expires.')
            ->action('Go to Billing', url('/company/billing'))
            ->line('Have questions? Contact our support team.');
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
