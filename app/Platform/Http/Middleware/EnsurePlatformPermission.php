<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPermission
{
    /**
     * ADR-863 — optional second argument, a READ right : `platform.permission:manage_modules,view_modules` lets a
     * holder of view_modules through on safe methods only (GET/HEAD). Every write still requires $permission, so the
     * manage right is never weakened ; a route without the second argument behaves exactly as before.
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $readPermission = null): Response
    {
        $user = $request->user('platform');

        // ADR-798 : « a|b » = l'une des permissions suffit (droit complet historique OU droit fin).
        $any = explode('|', $permission);
        if ($readPermission !== null && $request->isMethodSafe()) {
            $any = [...$any, ...explode('|', $readPermission)];
        }
        if (!$user || !collect($any)->contains(fn ($p) => $user->hasPermission($p))) {
            return response()->json([
                'message' => 'Permission required: ' . $permission,
            ], 403);
        }

        return $next($request);
    }
}
