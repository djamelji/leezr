<?php

namespace App\Notifications\Security;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-838 (Leezr 4.2 M2) — security notice sent to the account owner after a sensitive change on the account
 * (2FA disabled, backup codes regenerated, password changed or set by an administrator). It never contains a secret.
 */
class AccountSecurityNotification extends Notification
{
    public const EVENTS = ['two_factor_disabled', 'backup_codes_regenerated', 'password_set_by_admin', 'password_changed'];

    public function __construct(public string $event, public string $space = 'Leezr') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('accountSecurity.mail.subject'))
            ->greeting(__('accountSecurity.mail.greeting'))
            ->line(__('accountSecurity.mail.'.$this->event, ['space' => $this->space]))
            ->line(__('accountSecurity.mail.not_you'))
            ->salutation(__('accountSecurity.mail.salutation'));
    }
}
