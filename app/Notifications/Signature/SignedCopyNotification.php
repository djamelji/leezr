<?php

namespace App\Notifications\Signature;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * W-SIGNATURE-1 (ADR-651) — mise à disposition de la copie signée (au collaborateur), si activé.
 */
class SignedCopyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $documentLabel,
        public readonly string $downloadUrl,
        public readonly ?string $companyName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Document signé disponible : ' . $this->documentLabel)
            ->greeting('Bonjour ' . $this->recipientName . ',')
            ->line('Le document « ' . $this->documentLabel . ' »' . ($this->companyName ? ' de ' . $this->companyName : '') . ' a été signé.')
            ->action('Accéder au document signé', $this->downloadUrl)
            ->line('Conservez ce document pour vos archives.');
    }
}
