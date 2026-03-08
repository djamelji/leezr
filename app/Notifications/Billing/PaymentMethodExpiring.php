<?php

namespace App\Notifications\Billing;

use App\Core\Billing\CompanyPaymentProfile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a payment method (card) is expiring within 30 days.
 */
class PaymentMethodExpiring extends Notification
{
    public function __construct(
        private readonly CompanyPaymentProfile $profile,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $meta = $this->profile->metadata ?? [];
        $brand = ucfirst($meta['brand'] ?? 'card');
        $last4 = $meta['last4'] ?? '****';
        $expMonth = str_pad($meta['exp_month'] ?? '??', 2, '0', STR_PAD_LEFT);
        $expYear = $meta['exp_year'] ?? '????';

        return (new MailMessage)
            ->subject("Your {$brand} ending in {$last4} is expiring soon")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your payment method **{$brand} ending in {$last4}** expires on **{$expMonth}/{$expYear}**.")
            ->line('Please update your payment method to avoid service interruption.')
            ->action('Update Payment Method', url('/company/billing'))
            ->line('Thank you for keeping your account up to date.');
    }

    public function getProfile(): CompanyPaymentProfile
    {
        return $this->profile;
    }
}
