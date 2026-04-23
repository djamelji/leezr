<?php

namespace App\Notifications\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public ?int $emailLogId = null;
    public ?string $emailMessageId = null;
    public ?string $cc = null;
    public ?string $bcc = null;

    public function __construct(
        private string $emailSubject,
        private string $emailBody,
    ) {
        $this->onQueue('default');
    }

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Use custom branded template instead of default Laravel notification
        // (default adds "Hello!" + "Regards" which triggers spam filters)
        // HTML + text/plain multipart (Gmail penalizes HTML-only)
        $data = ['body' => $this->emailBody, 'subject' => $this->emailSubject];
        $mail = (new MailMessage)
            ->subject($this->emailSubject)
            ->view(['emails.manual', 'emails.manual-text'], $data);

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
