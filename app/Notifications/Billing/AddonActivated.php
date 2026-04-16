<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a paid addon module is activated and invoiced.
 */
class AddonActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly string $moduleName,
        private readonly Invoice $invoice,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.addon_activated.subject', ['module' => $this->moduleName]))
            ->view('emails.billing.addon-activated', [
                'user' => $notifiable,
                'moduleName' => $this->moduleName,
                'invoice' => $this->invoice,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
