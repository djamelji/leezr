<?php

namespace App\Notifications\Signature;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * W-SIGNATURE-1 (ADR-651) — invitation à signer (lien sécurisé). Envoyée au signataire (user ou email externe).
 */
class SignatureInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $signerName,
        public readonly string $documentLabel,
        public readonly string $signUrl,
        public readonly ?string $companyName = null,
        public readonly ?string $expiresAt = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $msg = (new MailMessage())
            ->subject('Signature requise : ' . $this->documentLabel)
            ->greeting('Bonjour ' . $this->signerName . ',')
            ->line(($this->companyName ? $this->companyName . ' vous' : 'Vous') . ' invite à signer le document « ' . $this->documentLabel . ' ».')
            ->action('Consulter et signer', $this->signUrl);

        if ($this->expiresAt) {
            $msg->line('Ce lien expire le ' . $this->expiresAt . '.');
        }

        return $msg->line('Ce lien est personnel : ne le transférez pas.');
    }
}
