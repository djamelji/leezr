<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Notifications\Signature\SignatureInvitationNotification;
use App\Notifications\Signature\SignedCopyNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * W-SIGNATURE-1 (ADR-651) — envoi des e-mails de signature via le système de notifications existant.
 *
 * Best-effort : un échec d'envoi ne casse jamais la transition d'état (il est journalisé). Aucun
 * envoi fictif : on passe par le canal mail réel (log/Mailpit en dev).
 */
class SignatureNotifier
{
    public function sendInvitation(string $email, SignatureInvitationNotification $notification): bool
    {
        try {
            Notification::route('mail', $email)->notify($notification);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Signature invitation email failed', ['email' => $email, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function sendSignedCopy(string $email, SignedCopyNotification $notification): bool
    {
        try {
            Notification::route('mail', $email)->notify($notification);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Signed copy email failed', ['email' => $email, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
