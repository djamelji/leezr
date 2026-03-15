<?php

namespace App\Core\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Enable 2FA for a user: generate secret + backup codes.
     * Returns the secret and QR code provisioning URI.
     */
    public function enable(Authenticatable $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $backupCodes = $this->generateBackupCodes();

        TwoFactorCredential::updateOrCreate(
            [
                'authenticatable_type' => get_class($user),
                'authenticatable_id' => $user->getAuthIdentifier(),
            ],
            [
                'secret' => $secret,
                'backup_codes' => $backupCodes,
                'confirmed_at' => null,
                'enabled' => false,
            ],
        );

        $appName = config('app.name', 'Leezr');
        $qrUrl = $this->google2fa->getQRCodeUrl($appName, $user->email, $secret);

        return [
            'secret' => $secret,
            'qr_url' => $qrUrl,
            'backup_codes' => $backupCodes,
        ];
    }

    /**
     * Confirm 2FA setup by verifying the first TOTP code.
     */
    public function confirm(Authenticatable $user, string $code): bool
    {
        $credential = $this->getCredential($user);
        if (!$credential || $credential->enabled) {
            return false;
        }

        if (!$this->google2fa->verifyKey($credential->secret, $code)) {
            return false;
        }

        $credential->update([
            'confirmed_at' => now(),
            'enabled' => true,
            'last_used_at' => now(),
        ]);

        return true;
    }

    /**
     * Verify a TOTP code or backup code during login.
     */
    public function verify(Authenticatable $user, string $code): bool
    {
        $credential = $this->getCredential($user);
        if (!$credential || !$credential->enabled) {
            return false;
        }

        // Try TOTP first
        if ($this->google2fa->verifyKey($credential->secret, $code)) {
            $credential->update(['last_used_at' => now()]);

            return true;
        }

        // Try backup codes
        return $this->consumeBackupCode($credential, $code);
    }

    /**
     * Disable 2FA for a user (requires password verification upstream).
     */
    public function disable(Authenticatable $user): void
    {
        TwoFactorCredential::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->delete();
    }

    /**
     * Regenerate backup codes.
     */
    public function regenerateBackupCodes(Authenticatable $user): array
    {
        $credential = $this->getCredential($user);
        if (!$credential || !$credential->enabled) {
            return [];
        }

        $codes = $this->generateBackupCodes();
        $credential->update(['backup_codes' => $codes]);

        return $codes;
    }

    /**
     * Check if 2FA is enabled and confirmed for a user.
     */
    public function isEnabled(Authenticatable $user): bool
    {
        $credential = $this->getCredential($user);

        return $credential && $credential->enabled && $credential->confirmed_at !== null;
    }

    private function getCredential(Authenticatable $user): ?TwoFactorCredential
    {
        return TwoFactorCredential::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->first();
    }

    private function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    private function consumeBackupCode(TwoFactorCredential $credential, string $code): bool
    {
        $codes = $credential->backup_codes ?? [];
        $normalized = Str::upper(trim($code));

        $index = array_search($normalized, $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $credential->update([
            'backup_codes' => array_values($codes),
            'last_used_at' => now(),
        ]);

        return true;
    }
}
