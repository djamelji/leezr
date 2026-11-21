<?php

namespace App\Core\Auth;

use App\Notifications\Security\AccountSecurityNotification;
use App\Platform\Models\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR-838 (Leezr 4.2 M2) — shared consequences of a credential change, for both scopes (Company `users`,
 * Platform `platform_users`) : end the account's sessions and tell the owner. Reuses the database session store
 * (ScopedDatabaseSessionHandler columns, ADR-801.1) and the remember token ; no new mechanism.
 */
final class AccountSecurity
{
    /**
     * Ends every session of the account (optionally keeping the caller's current one) and rotates the
     * remember token so a « remember me » cookie cannot reopen a session. Returns the number of sessions ended.
     */
    public static function revokeSessions(Authenticatable $user, ?string $keepSessionId = null): int
    {
        $column = $user instanceof PlatformUser ? ScopedDatabaseSessionHandler::COLUMNS['platform'] : ScopedDatabaseSessionHandler::COLUMNS['web'];
        $ended = 0;

        if (config('session.driver') === 'database') {
            $query = DB::table(config('session.table', 'sessions'))->where($column, $user->getAuthIdentifier());
            if ($keepSessionId) {
                $query->where('id', '!=', $keepSessionId);
            }
            $ended = $query->delete();
        }

        if (method_exists($user, 'setRememberToken') && $user->getRememberTokenName()) {
            $user->setRememberToken(Str::random(60));
            $user->save();
        }
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete(); // Sanctum personal tokens, if any
        }

        return $ended;
    }

    /** Security notice to the account owner ; a mail failure never cancels the (already applied) change. */
    public static function notify(Authenticatable $user, string $event, string $space = 'Leezr'): void
    {
        try {
            $user->notify(new AccountSecurityNotification($event, $space));
        } catch (\Throwable $e) {
            Log::warning('[account-security] notice not sent', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
