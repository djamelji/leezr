<?php

namespace App\Notifications\DataExport;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ADR-845 — the requester is told the archive is ready (no link in the e-mail : download from the application, signed in). */
class CompanyDataExportReadyNotification extends Notification
{
    public function __construct(public string $companyName, public $expiresAt, public bool $partial = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('companyExport.mail.subject', ['company' => $this->companyName]))
            ->greeting(__('companyExport.mail.greeting'))
            ->line(__($this->partial ? 'companyExport.mail.partial' : 'companyExport.mail.ready', ['company' => $this->companyName]))
            ->line(__('companyExport.mail.expires', ['date' => $this->expiresAt?->format('d/m/Y H:i')]))
            ->action(__('companyExport.mail.action'), url('/company/billing/closure'))
            ->line(__('companyExport.mail.notYou'));
    }
}
