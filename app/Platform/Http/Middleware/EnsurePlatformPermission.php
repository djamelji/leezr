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

        if (!$user || !$user->hasPermission($permission)) {
            return response()->json([
                'message' => 'Permission required: ' . $permission,
            ], 403);
        }

        return $next($request);
    }
}
