<?php

namespace App\Notifications\Members;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M7 LOT 2 — invitation of an EXISTING account to join a company. Nothing is activated by this
 * e-mail: the recipient signs in with his own account and accepts or declines.
 */
class CompanyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $companyName,
        public ?string $inviterName,
        public string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Invitation à rejoindre {$this->companyName}")
            ->greeting('Bonjour,')
            ->line($this->inviterName
                ? "{$this->inviterName} vous invite à rejoindre l'espace de {$this->companyName}."
                : "Vous êtes invité(e) à rejoindre l'espace de {$this->companyName}.")
            ->line("Aucun accès n'est activé tant que vous n'avez pas accepté cette invitation.")
            ->action("Voir l'invitation", $this->url)
            ->line("Si vous n'attendiez pas cette invitation, vous pouvez la refuser ou l'ignorer.");
    }
}
