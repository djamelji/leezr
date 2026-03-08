<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a new invoice is finalized (renewal, addon, proration).
 */
class InvoiceCreated extends Notification
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $amount = number_format($this->invoice->amount_due / 100, 2);
        $currency = strtoupper($this->invoice->currency ?? 'EUR');

        return (new MailMessage)
            ->subject("Invoice #{$this->invoice->number} — {$amount} {$currency}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("A new invoice has been created for your account.")
            ->line("**Invoice #{$this->invoice->number}** — {$amount} {$currency}")
            ->action('View Billing', url('/company/billing'))
            ->line('Thank you for your business.');
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
