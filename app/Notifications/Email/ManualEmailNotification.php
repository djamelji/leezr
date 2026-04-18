<?php

namespace App\Notifications\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ManualEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public ?int $emailLogId = null;
    public ?string $emailMessageId = null;
    public ?string $cc = null;
    public ?string $bcc = null;

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
        $mail = (new MailMessage)
            ->subject($this->emailSubject)
            ->line(new HtmlString($this->emailBody));

        if ($this->cc) {
            foreach (array_filter(array_map('trim', explode(',', $this->cc))) as $email) {
                $mail->cc($email);
            }
        }

        if ($this->bcc) {
            foreach (array_filter(array_map('trim', explode(',', $this->bcc))) as $email) {
                $mail->bcc($email);
            }
        }

        return $mail;
    }
}
