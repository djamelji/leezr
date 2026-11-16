<?php

namespace App\Notifications\Onboarding;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-796.1 — invitation of a recruit to complete his onboarding file. Sent IMMEDIATELY (never queued :
 * the personal link must not be stored in a job payload). Nothing is created by opening it.
 */
class OnboardingInvitationNotification extends Notification
{
    public function __construct(public string $companyName, public string $url, public string $expiresOn) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('onboarding.invite_subject', ['company' => $this->companyName]))
            ->greeting(__('onboarding.invite_greeting'))
            ->line(__('onboarding.invite_line', ['company' => $this->companyName]))
            ->action(__('onboarding.invite_action'), $this->url)
            ->line(__('onboarding.invite_expiry', ['date' => $this->expiresOn]))
            ->line(__('onboarding.invite_personal'));
    }
}
