<?php

namespace App\Notifications\Billing;

use App\Core\Billing\CompanyPaymentProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-272: Sent when a payment method (card) is expiring within 30 days.
 */
class PaymentMethodExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly CompanyPaymentProfile $profile,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.payment_method_expiring.subject', ['brand' => $this->profile->brand, 'last4' => $this->profile->last4]))
            ->view('emails.billing.payment-method-expiring', [
                'user' => $notifiable,
                'profile' => $this->profile,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }

    public function getProfile(): CompanyPaymentProfile
    {
        return $this->profile;
    }
}
