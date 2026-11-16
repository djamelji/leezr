<?php

namespace App\Core\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-801.1 — logging out of ONE scope (Company `web` or `platform`) must not log the other scope out.
 *
 * Both scopes share the session cookie, each under its own login key. Previously each logout called
 * `session()->invalidate()`, destroying the other scope's login as a side effect. Now :
 *  - the scope's guard is logged out and its pending-2FA key forgotten ;
 *  - if the OTHER scope is still logged in → the session id is rotated (`migrate(true)`, old id destroyed :
 *    no fixation/replay of the pre-logout id) and the CSRF token regenerated ;
 *  - otherwise → full invalidation, exactly as before.
 */
final class ScopedLogout
{
    private const PENDING_2FA = ['web' => '2fa_pending_user_id', 'platform' => '2fa_pending_platform_user_id'];

    public static function end(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $session->forget(self::PENDING_2FA[$guard] ?? []);

        $other = Auth::guard($guard === 'platform' ? 'web' : 'platform');
        if ($other instanceof SessionGuard && $session->has($other->getName())) {
            $session->migrate(true);
        } else {
            $session->invalidate();
        }
        $session->regenerateToken();
    }
}
