<?php

namespace App\Notifications\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public ?int $emailLogId = null;
    public ?string $emailMessageId = null;

    public function __construct(
        private string $emailSubject,
        private string $emailBody,
    ) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->emailSubject)
            ->line($this->emailBody);
    }
}
