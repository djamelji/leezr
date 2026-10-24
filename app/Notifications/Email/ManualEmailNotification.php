<?php

namespace App\Notifications\Email;

use App\Core\Email\EmailAttachment;
use App\Core\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ManualEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public ?int $emailLogId = null;
    public ?string $emailMessageId = null;
    public ?string $cc = null;
    public ?string $bcc = null;

    /** ADR-603 (LOT 2): attachment IDs resolved at render time (order-independent of DB linking). */
    public array $attachmentIds = [];

    // ADR-603 (LOT 2): retry transient SMTP failures (e.g. 450 greylisting) with backoff.
    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

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
        // Configure SMTP BEFORE MailChannel selects the transport.
        // handleMessageSending() event is too late — mailer is already resolved.
        if (Config::get('mail.default') !== 'dynamic') {
            app(EmailService::class)->configureSmtp();
        }

        // Custom branded template + multipart HTML/text (no "Hello!/Regards" spam trigger)
        // emailLogId/emailMessageId in view data so MessageSent event can update EmailLog status
        $data = [
            'body' => $this->emailBody,
            'subject' => $this->emailSubject,
            'emailLogId' => $this->emailLogId,
            'emailMessageId' => $this->emailMessageId,
        ];
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

        // ADR-603 (LOT 2): actually attach files to the outgoing message (previously only linked in
        // DB → recipients received nothing). Read bytes via the storage disk so it works on any disk.
        foreach (EmailAttachment::whereIn('id', $this->attachmentIds)->get() as $att) {
            $disk = Storage::disk($att->disk ?: 'local');
            if ($disk->exists($att->path)) {
                $mail->attachData(
                    $disk->get($att->path),
                    $att->original_filename ?: $att->filename,
                    ['mime' => $att->mime_type],
                );
            }
        }

        return $mail;
    }
}
