<?php

namespace App\Notifications;

use App\Core\Audit\PlatformAuditLog;
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
class BillingCriticalAlert extends Notification
{
    public function __construct(
        private readonly PlatformAuditLog $auditLog,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $metadata = $this->auditLog->metadata ?? [];

        return (new MailMessage)
            ->subject("[Leezr Billing] Critical: {$this->auditLog->action}")
            ->line("A critical billing event has been detected.")
            ->line("**Action:** {$this->auditLog->action}")
            ->line("**Target:** {$this->auditLog->target_type}:{$this->auditLog->target_id}")
            ->line("**Severity:** {$this->auditLog->severity}")
            ->line('**Metadata:** '.json_encode($metadata, JSON_PRETTY_PRINT))
            ->line("**Time:** {$this->auditLog->created_at}")
            ->action('View Platform Dashboard', url('/platform/audit'));
    }

    public function getAuditLog(): PlatformAuditLog
    {
        return $this->auditLog;
    }
}
