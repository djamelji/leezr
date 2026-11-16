<?php

namespace App\Notifications\Onboarding;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-796.1 — one-time verification code of an onboarding file. Sent IMMEDIATELY (never queued : the
 * code must not be stored in a job payload) through the transactional mail channel.
 */
class OnboardingCodeNotification extends Notification
{
    public function __construct(public string $companyName, public string $code, public int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('onboarding.code_subject', ['company' => $this->companyName]))
            ->line(__('onboarding.code_mail', ['code' => $this->code, 'company' => $this->companyName, 'minutes' => $this->minutes]))
            ->line(__('onboarding.code_mail_ignore'));
    }
}
