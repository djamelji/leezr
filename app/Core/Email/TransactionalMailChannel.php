<?php

namespace App\Core\Email;

use App\Platform\Models\PlatformSetting;

/**
 * ADR-796.1 — is a REAL transactional e-mail channel available for a secret-bearing message (one-time
 * code, personal link) ? Fail-closed :
 *  - `log` / `array` transports never count (the secret would land in a log file, or nowhere) — except
 *    `array` under the test runner ;
 *  - outside production, only a LOCAL catch-all SMTP (Mailpit) is accepted : DEV never reaches a real
 *    recipient.
 * The effective transport honours the platform SMTP settings exactly like EmailService::configureSmtp.
 */
final class TransactionalMailChannel
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'mailpit'];

    public static function available(): bool
    {
        $platformHost = (PlatformSetting::first()?->email ?? [])['smtp_host'] ?? null;
        if (! empty($platformHost)) {
            [$transport, $host] = ['smtp', (string) $platformHost];
        } else {
            $mailer = config('mail.mailers.'.config('mail.default'), []);
            [$transport, $host] = [(string) ($mailer['transport'] ?? config('mail.default')), (string) ($mailer['host'] ?? '')];
        }
        if ($transport === 'array' && app()->runningUnitTests()) {
            return true; // runner de tests : transport factice, rien ne sort
        }
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }
        if (! app()->isProduction()) {
            return $transport === 'smtp' && in_array(strtolower($host), self::LOCAL_HOSTS, true);
        }

        return true;
    }
}
