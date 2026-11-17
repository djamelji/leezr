<?php

namespace App\Notifications\Referral;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ADR-819 — a relative recommended by an employee is invited to apply (sent immediately, never queued : personal link). */
class ReferralInvitationNotification extends Notification
{
    public function __construct(public string $companyName, public string $referrerName, public string $position, public string $url, public string $expiresOn) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('referral.mail.subject', ['company' => $this->companyName]))
            ->greeting(__('referral.mail.greeting'))
            ->line(__('referral.mail.line', ['referrer' => $this->referrerName, 'company' => $this->companyName, 'position' => $this->position]))
            ->action(__('referral.mail.action'), $this->url)
            ->line(__('referral.mail.expiry', ['date' => $this->expiresOn]))
            ->line(__('referral.mail.optional'));
    }
}
