<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-226: Sent when dunning retries are exhausted and the account is suspended.
 */
class AccountSuspended extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account has been suspended')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Your account has been suspended due to unpaid invoices.')
            ->line('Please resolve your outstanding balance to restore access to your account.')
            ->action('Go to Billing', url('/company/billing'))
            ->line('If you need assistance, please contact our support team.');
    }
}
