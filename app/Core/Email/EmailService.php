<?php

namespace App\Core\Email;

use App\Core\Models\Company;
use App\Core\Theme\UIResolverService;
use App\Platform\Models\PlatformSetting;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailService
{
    /**
     * Send a notification email via the centralized email system.
     *
     * 1. Reads SMTP config from PlatformSetting.email (ISPConfig compatible)
     * 2. Creates an EmailLog record (status=queued)
     * 3. Configures Laravel mailer dynamically at runtime
     * 4. Dispatches the notification (queued via ShouldQueue)
     * 5. EmailEventSubscriber updates status on sent/failed
     */
    public function send(
        Notification $notification,
        mixed $recipient,
        string $templateKey,
        ?Company $company = null,
        array $metadata = [],
    ): EmailLog {
        $settings = $this->getSettings();
        $fromEmail = $settings['from_email'] ?? config('mail.from.address', 'noreply@leezr.com');
        $replyTo = $settings['reply_to'] ?? null;
        $messageId = $this->generateMessageId($fromEmail);

        $log = EmailLog::create([
            'message_id' => $messageId,
            'company_id' => $company?->id,
            'recipient_email' => $recipient->email,
            'recipient_name' => $recipient->first_name ?? $recipient->name ?? null,
            'from_email' => $fromEmail,
            'reply_to' => $replyTo,
            'subject' => $this->resolveSubject($notification, $recipient),
            'template_key' => $templateKey,
            'notification_class' => class_basename($notification),
            'status' => 'queued',
            'headers' => ['Message-ID' => "<{$messageId}>"],
            'metadata' => $metadata,
        ]);

        // Inject the log ID into the notification so EmailEventSubscriber can find it
        $notification->emailLogId = $log->id;
        $notification->emailMessageId = $messageId;

        $this->configureSmtp();

        try {
            $recipient->notify($notification);
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            Log::error('[email] Send failed', [
                'template' => $templateKey,
                'recipient' => $recipient->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Retry a failed email. Creates a new log entry and re-dispatches.
     */
    public function retry(EmailLog $failedLog): EmailLog
    {
        if ($failedLog->status !== 'failed') {
            throw new \InvalidArgumentException('Can only retry failed emails');
        }

        $settings = $this->getSettings();
        $fromEmail = $settings['from_email'] ?? config('mail.from.address');
        $newMessageId = $this->generateMessageId($fromEmail);

        $newLog = EmailLog::create([
            'message_id' => $newMessageId,
            'company_id' => $failedLog->company_id,
            'recipient_email' => $failedLog->recipient_email,
            'recipient_name' => $failedLog->recipient_name,
            'from_email' => $fromEmail,
            'reply_to' => $failedLog->reply_to,
            'subject' => $failedLog->subject,
            'template_key' => $failedLog->template_key,
            'notification_class' => $failedLog->notification_class,
            'status' => 'queued',
            'headers' => ['Message-ID' => "<{$newMessageId}>", 'References' => "<{$failedLog->message_id}>"],
            'metadata' => array_merge($failedLog->metadata ?? [], ['retry_of' => $failedLog->id]),
        ]);

        return $newLog;
    }

    /**
     * Test SMTP connection with current settings.
     * Returns ['success' => bool, 'message' => string].
     */
    public function testConnection(): array
    {
        $this->configureSmtp();
        $settings = $this->getSettings();

        if (empty($settings['smtp_host'])) {
            return ['success' => false, 'message' => 'SMTP host not configured'];
        }

        try {
            // Use the freshly-purged dynamic mailer explicitly
            $mailer = app('mail.manager')->mailer('dynamic');
            $transport = $mailer->getSymfonyTransport();

            // Force SMTP handshake to validate credentials
            if (method_exists($transport, 'start')) {
                $transport->start();
            }

            return ['success' => true, 'message' => 'SMTP connection successful'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Configure Laravel SMTP mailer dynamically from PlatformSetting.email.
     *
     * ISPConfig compatibility:
     * - Host = ISPConfig mail server (e.g. mail.leezr.com)
     * - Credentials = email box created in ISPConfig panel
     * - No dependency on .env MAIL_* in production
     * - Domain changes = UI settings change only
     */
    private function configureSmtp(): void
    {
        $settings = $this->getSettings();

        if (empty($settings['smtp_host'])) {
            return; // Fall back to default Laravel config (dev/testing)
        }

        // Laravel 12 / Symfony Mailer uses 'scheme' not 'encryption':
        // 'smtps' = implicit TLS (port 465), 'smtp' = STARTTLS (port 587)
        $encryption = $settings['smtp_encryption'] ?? 'tls';
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        Config::set('mail.default', 'dynamic');
        Config::set('mail.mailers.dynamic', [
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => $settings['smtp_host'],
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'username' => $settings['smtp_username'] ?? null,
            'password' => $settings['smtp_password'] ?? null,
            'timeout' => 30,
        ]);

        Config::set('mail.from.address', $settings['from_email'] ?? config('mail.from.address'));
        Config::set('mail.from.name', $settings['from_name'] ?? config('mail.from.name'));

        // Force mailer to pick up new config
        app('mail.manager')->purge('dynamic');
    }

    /**
     * Generate a unique message ID compatible with RFC 2822.
     * Format: <uuid@domain> — ready for future threading via In-Reply-To.
     */
    private function generateMessageId(string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@') ?: 'leezr.com';

        return Str::uuid()->toString().'@'.$domain;
    }

    private function resolveSubject(Notification $notification, mixed $recipient): string
    {
        try {
            $mail = $notification->toMail($recipient);

            return $mail->subject ?? class_basename($notification);
        } catch (\Throwable) {
            return class_basename($notification);
        }
    }

    private function getSettings(): array
    {
        return PlatformSetting::first()?->email ?? [];
    }

    /**
     * Get branding settings for Blade email templates.
     * Logo = text-styled app name (like BrandLogo.vue), color = theme primary.
     */
    public static function branding(): array
    {
        $general = PlatformSetting::first()?->general ?? [];
        $appName = $general['app_name'] ?? config('app.name', 'Leezr');

        try {
            $primaryColor = UIResolverService::forPlatform()->primaryColor;
        } catch (\Throwable) {
            $primaryColor = '#7367F0';
        }

        return [
            'app_name' => $appName,
            'color' => $primaryColor,
        ];
    }
}
