<?php

namespace App\Notifications\Billing;

use App\Core\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-226: Sent when a Stripe off-session payment fails during dunning.
 * Encourages the customer to update their payment method.
 */
class PaymentFailed extends Notification implements ShouldQueue
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
            ->subject(__('email.billing.payment_failed.subject'))
            ->view('emails.billing.payment-failed', [
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
