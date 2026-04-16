<?php

namespace App\Notifications;

use App\Core\Audit\PlatformAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-140 D3d: Critical billing alert notification.
 *
 * Dispatched when a platform audit log with severity=critical is created
 * and billing.alerting.enabled is true.
 *
 * Sent via mail channel to the configured alert email.
 */
class BillingCriticalAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public ?int $emailLogId = null;

    public ?string $emailMessageId = null;

    public function __construct(
        private readonly PlatformAuditLog $auditLog,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.billing.critical_alert.subject', ['action' => $this->auditLog->action]))
            ->view('emails.billing.critical-alert', [
                'auditLog' => $this->auditLog,
                'emailLogId' => $this->emailLogId,
                'emailMessageId' => $this->emailMessageId,
            ]);
    }

    public function getAuditLog(): PlatformAuditLog
    {
        return $this->auditLog;
    }
}
