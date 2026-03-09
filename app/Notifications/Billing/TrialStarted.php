<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-286: Sent when a company starts a trial subscription.
 */
class TrialStarted extends Notification
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
        $days = $this->subscription->trial_ends_at->diffInDays(now());

        return (new MailMessage)
            ->subject('Your free trial has started!')
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Welcome! Your **{$days}-day free trial** has started.")
            ->line("Your trial will end on **{$endsAt}**. You have full access to all features during this period.")
            ->action('Go to Billing', url('/company/billing'))
            ->line('Have questions? Contact our support team.');
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
