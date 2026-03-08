<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when an invoice is successfully paid (Stripe, wallet, or manual).
 */
class PaymentReceived extends Notification
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
        $amount = number_format($this->invoice->amount / 100, 2);
        $currency = strtoupper($this->invoice->currency ?? 'EUR');

        return (new MailMessage)
            ->subject("Payment received — {$amount} {$currency}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("We've received your payment of **{$amount} {$currency}** for invoice #{$this->invoice->number}.")
            ->action('View Billing', url('/company/billing'))
            ->line('Thank you for your payment.');
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
