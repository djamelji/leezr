<?php

namespace App\Company\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $company = $request->attributes->get('company');

        if (!$company) {
            return response()->json([
                'message' => 'Company context not set.',
            ], 500);
        }

        $user = $request->user();

        if (!$user || !$user->hasCompanyPermission($company, $permission)) {
            return response()->json([
                'message' => 'Permission required: ' . $permission,
            ], 403);
        }

        return $next($request);
    }
}
