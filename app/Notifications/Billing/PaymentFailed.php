<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-226: Sent when a Stripe off-session payment fails during dunning.
 * Encourages the customer to update their payment method.
 */
class PaymentFailed extends Notification
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
            ->subject('Payment failed — action required')
            ->greeting("Hello {$notifiable->first_name},")
            ->line("We were unable to process a payment of {$amount} {$currency} for your subscription.")
            ->line('Please update your payment method to avoid service interruption.')
            ->action('Go to Billing', url('/company/billing'))
            ->line('If you believe this is an error, please contact our support team.');
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
