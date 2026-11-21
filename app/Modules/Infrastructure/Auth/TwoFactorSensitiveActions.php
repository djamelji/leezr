<?php

namespace App\Modules\Infrastructure\Auth;

use App\Core\Audit\AuditAction;
use App\Core\Auth\AccountSecurity;
use App\Core\Auth\TwoFactorService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * ADR-838 (Leezr 4.2 M2) — the two sensitive 2FA operations, identical for Company and Platform.
 * Each scope's controller passes its own user and its own audit writer ; the factor check is TwoFactorService's.
 *
 * Audit : one event per attempt (success or refusal) with the result and the factor TYPE — never a code,
 * a password or a backup code.
 *
 * @phpstan-type Audit callable(string $action, array $metadata, string $severity): void
 */
final class TwoFactorSensitiveActions
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /** Disable : current password AND a fresh factor (TOTP code or backup code). */
    public function disable(Request $request, Authenticatable $user, callable $audit): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ], ['code.required' => __('accountSecurity.code_required')]);

        if ($blocked = $this->blocked($user, $audit, AuditAction::TWO_FACTOR_DISABLE_REFUSED)) {
            return $blocked;
        }
        if (! $this->twoFactor->isEnabled($user)) {
            return $this->refuse('code', __('accountSecurity.not_enabled'));
        }
        if (! Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
            return $this->failed($user, $audit, AuditAction::TWO_FACTOR_DISABLE_REFUSED, 'password', 'invalid_password', __('accountSecurity.password_invalid'));
        }

        $factor = $this->twoFactor->disableWithCode($user, (string) $request->input('code'));
        if ($factor === null) {
            return $this->failed($user, $audit, AuditAction::TWO_FACTOR_DISABLE_REFUSED, 'code', 'invalid_code', __('accountSecurity.code_invalid'));
        }

        $audit(AuditAction::TWO_FACTOR_DISABLED, ['result' => 'success', 'factor' => $factor], 'warning');
        AccountSecurity::notify($user, 'two_factor_disabled');

        return response()->json(['message' => __('accountSecurity.disabled')]);
    }

    /** Regenerate backup codes : a fresh factor ; the new codes are returned once and never stored in clear. */
    public function regenerateBackupCodes(Request $request, Authenticatable $user, callable $audit): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']], ['code.required' => __('accountSecurity.code_required')]);

        if ($blocked = $this->blocked($user, $audit, AuditAction::TWO_FACTOR_BACKUP_CODES_REFUSED)) {
            return $blocked;
        }
        if (! $this->twoFactor->isEnabled($user)) {
            return $this->refuse('code', __('accountSecurity.not_enabled'));
        }

        $result = $this->twoFactor->regenerateBackupCodesWithCode($user, (string) $request->input('code'));
        if ($result === null) {
            return $this->failed($user, $audit, AuditAction::TWO_FACTOR_BACKUP_CODES_REFUSED, 'code', 'invalid_code', __('accountSecurity.code_invalid'));
        }

        $audit(AuditAction::TWO_FACTOR_BACKUP_CODES_REGENERATED, ['result' => 'success', 'factor' => $result['factor'], 'count' => count($result['codes'])], 'warning');
        AccountSecurity::notify($user, 'backup_codes_regenerated');

        return response()->json(['message' => __('accountSecurity.codes_regenerated'), 'backup_codes' => $result['codes']])
            ->header('Cache-Control', 'no-store');
    }

    private function blocked(Authenticatable $user, callable $audit, string $action): ?JsonResponse
    {
        $retry = $this->twoFactor->sensitiveRetryAfter($user);
        if ($retry === 0) {
            return null;
        }
        $audit($action, ['result' => 'refused', 'reason' => 'too_many_attempts'], 'warning');

        return response()->json([
            'message' => __('accountSecurity.too_many_attempts', ['minutes' => (int) ceil($retry / 60)]),
            'reason' => 'too_many_attempts',
        ], 429)->header('Retry-After', (string) $retry);
    }

    private function failed(Authenticatable $user, callable $audit, string $action, string $field, string $reason, string $message): JsonResponse
    {
        $this->twoFactor->recordSensitiveFailure($user);
        $audit($action, ['result' => 'refused', 'reason' => $reason], 'warning');

        return $this->refuse($field, $message, $reason);
    }

    private function refuse(string $field, string $message, string $reason = 'not_enabled'): JsonResponse
    {
        return response()->json(['message' => $message, 'reason' => $reason, 'errors' => [$field => [$message]]], 422);
    }
}
