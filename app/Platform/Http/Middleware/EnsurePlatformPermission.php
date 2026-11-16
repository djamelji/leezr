<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('platform');

        // ADR-798 : « a|b » = l'une des permissions suffit (droit complet historique OU droit fin).
        $any = explode('|', $permission);
        if (!$user || !collect($any)->contains(fn ($p) => $user->hasPermission($p))) {
            return response()->json([
                'message' => 'Permission required: ' . $permission,
            ], 403);
        }

        return $next($request);
    }
}
