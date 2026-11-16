<?php

namespace App\Notifications\Members;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-812 — invitation of a NEW account : the person was added to a company and chooses a password
 * to activate the access. Replaces the generic framework « password reset request » e-mail, which named
 * neither the company nor the invitation. Same token, same broker and same expiry as before.
 */
class AccountActivationNotification extends Notification
{
    public function __construct(
        public string $companyName,
        public ?string $inviterName,
        public string $token,
        public string $email,
        public int $expiresInMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return url('/reset-password?token='.$this->token.'&email='.urlencode($this->email));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $space = $this->companyName !== '' ? "l'espace de {$this->companyName}" : 'Leezr';

        return (new MailMessage())
            ->subject($this->companyName !== '' ? "Votre accès à {$this->companyName} sur Leezr" : 'Votre accès à Leezr')
            ->greeting('Bonjour,')
            ->line($this->inviterName
                ? "{$this->inviterName} vous a ajouté(e) à {$space}."
                : "Vous avez été ajouté(e) à {$space}.")
            ->line('Pour activer votre compte, choisissez votre mot de passe.')
            ->action('Activer mon compte', $this->url())
            ->line("Ce lien est valable {$this->expiresInMinutes} minutes. S'il a expiré, demandez à votre entreprise de renvoyer l'invitation.")
            ->line("Si vous n'attendiez pas cet e-mail, vous pouvez l'ignorer.")
            ->salutation('À bientôt sur Leezr');
    }
}
