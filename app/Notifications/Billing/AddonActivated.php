<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a paid addon module is activated and invoiced.
 */
class AddonActivated extends Notification
{
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
        $amount = number_format($this->invoice->amount_due / 100, 2);
        $currency = strtoupper($this->invoice->currency ?? 'EUR');

        return (new MailMessage)
            ->subject("Module \"{$this->moduleName}\" activated")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("The module **{$this->moduleName}** has been activated on your account.")
            ->line("An invoice of **{$amount} {$currency}** has been created.")
            ->action('View Billing', url('/company/billing'))
            ->line('Thank you for your purchase.');
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
