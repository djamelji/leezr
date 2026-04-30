<?php

namespace App\Core\Alerts\Notifications;

use App\Core\Alerts\PlatformAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-469: Email notification sent to platform admins when a critical alert fires,
 * and on each escalation cycle for unacknowledged critical alerts.
 */
class CriticalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PlatformAlert $alert,
        public int $escalationCount = 0,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $subject = $this->escalationCount > 0
            ? "[Leezr Alert] ESCALATION #{$this->escalationCount}: {$this->alert->title}"
            : "[Leezr Alert] {$this->alert->severity}: {$this->alert->title}";

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting("Alert: {$this->alert->title}")
            ->line("Severity: {$this->alert->severity}")
            ->line("Source: {$this->alert->source}");

        if ($this->alert->description) {
            $mail->line("Description: {$this->alert->description}");
        }

        if ($this->escalationCount > 0) {
            $mail->line("This alert has been escalated {$this->escalationCount} time(s) — it remains unacknowledged.");
        }

        $mail->line("Created: {$this->alert->created_at->toDateTimeString()}")
            ->action('View Alerts', url('/platform/alerts'))
            ->line('This alert requires your immediate attention.');

        return $mail;
    }
}
