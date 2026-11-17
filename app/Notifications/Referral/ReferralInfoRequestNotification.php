<?php

namespace App\Notifications\Referral;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ADR-819 — the company asks a referral candidate for more information (personal link, sent immediately). */
class ReferralInfoRequestNotification extends Notification
{
    public function __construct(public string $companyName, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('referral.mail.info_subject', ['company' => $this->companyName]))
            ->line(__('referral.mail.info_line', ['company' => $this->companyName]))
            ->action(__('referral.mail.info_action'), $this->url);
    }
}
