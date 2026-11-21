<?php

namespace App\Core\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
     * ADR-838 (Leezr 4.2 M2) — sensitive 2FA operations require a FRESH proof of the active factor
     * (current TOTP code, single use, or one backup code, consumed) on top of the session.
     * Failures are counted per account (5 per 15 min) ; the check and the change run in one transaction
     * under a row lock, so two concurrent requests can never both pass with the same code.
     */
    public const SENSITIVE_MAX_FAILURES = 5;

    public const SENSITIVE_DECAY_SECONDS = 900;

    /** Seconds before a new attempt is allowed, 0 when attempts are allowed. */
    public function sensitiveRetryAfter(Authenticatable $user): int
    {
        $key = $this->sensitiveKey($user);

        return RateLimiter::tooManyAttempts($key, self::SENSITIVE_MAX_FAILURES) ? max(1, RateLimiter::availableIn($key)) : 0;
    }

    public function recordSensitiveFailure(Authenticatable $user): void
    {
        RateLimiter::hit($this->sensitiveKey($user), self::SENSITIVE_DECAY_SECONDS);
    }

    /**
     * Disable 2FA after verifying the active factor. Returns the factor used ('totp'|'backup_code') or null when refused.
     */
    public function disableWithCode(Authenticatable $user, string $code): ?string
    {
        return $this->withVerifiedFactor($user, $code, function (TwoFactorCredential $credential) {
            $credential->delete();
        });
    }

    /**
     * Regenerate backup codes after verifying the active factor. The old codes stay valid until the new ones
     * are written (same transaction). Returns ['factor' => …, 'codes' => [...]] or null when refused.
     */
    public function regenerateBackupCodesWithCode(Authenticatable $user, string $code): ?array
    {
        $codes = $this->generateBackupCodes();
        $factor = $this->withVerifiedFactor($user, $code, function (TwoFactorCredential $credential) use ($codes) {
            $credential->forceFill(['backup_codes' => $codes])->save();
        });

        return $factor === null ? null : ['factor' => $factor, 'codes' => $codes];
    }

    /**
     * Disable 2FA without any factor check — internal callers only (no HTTP route uses it since ADR-838).
     */
    public function disable(Authenticatable $user): void
    {
        TwoFactorCredential::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->delete();
    }

    private function withVerifiedFactor(Authenticatable $user, string $code, callable $change): ?string
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $factor = DB::transaction(function () use ($user, $code, $change) {
            $credential = TwoFactorCredential::where('authenticatable_type', get_class($user))
                ->where('authenticatable_id', $user->getAuthIdentifier())
                ->lockForUpdate()->first();
            if (! $credential || ! $credential->enabled || $credential->confirmed_at === null) {
                return null;
            }

            $factor = null;
            if (preg_match('/^\d{6}$/', $code) === 1) {
                // single use : the same TOTP code never authorises a second sensitive operation
                $replayKey = '2fa-sensitive-otp:'.sha1(get_class($user).'|'.$user->getAuthIdentifier().'|'.$code);
                if ($this->google2fa->verifyKey($credential->secret, $code) && Cache::add($replayKey, 1, 120)) {
                    $factor = 'totp';
                }
            } else {
                $codes = $credential->backup_codes ?? [];
                $index = array_search(Str::upper($code), $codes, true);
                if ($index !== false) {
                    unset($codes[$index]);
                    $credential->forceFill(['backup_codes' => array_values($codes)]);
                    $factor = 'backup_code';
                }
            }
            if ($factor === null) {
                return null;
            }

            $credential->forceFill(['last_used_at' => now()])->save();
            $change($credential);

            return $factor;
        });

        if ($factor !== null) {
            RateLimiter::clear($this->sensitiveKey($user));
        }

        return $factor;
    }

    private function sensitiveKey(Authenticatable $user): string
    {
        return '2fa-sensitive:'.sha1(get_class($user).'|'.$user->getAuthIdentifier());
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
