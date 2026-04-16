<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a new invoice is finalized (renewal, addon, proration).
 */
class InvoiceCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.invoice_created.subject', ['number' => $this->invoice->number, 'amount' => number_format($this->invoice->total_amount / 100, 2), 'currency' => $this->invoice->currency]))
            ->view('emails.billing.invoice-created', [
                'user' => $notifiable,
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
