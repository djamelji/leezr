<?php

namespace App\Company\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    private const ROLE_HIERARCHY = [
        'user' => 0,
        'admin' => 1,
        'owner' => 2,
    ];

    public function handle(Request $request, Closure $next, string $minimumRole): Response
    {
        $company = $request->attributes->get('company');

        if (!$company) {
            return response()->json([
                'message' => 'Company context not set.',
            ], 500);
        }

        $userRole = $request->user()->roleIn($company);

        if (!$userRole) {
            return response()->json([
                'message' => 'You are not a member of this company.',
            ], 403);
        }

        $userLevel = self::ROLE_HIERARCHY[$userRole] ?? -1;
        $requiredLevel = self::ROLE_HIERARCHY[$minimumRole] ?? 999;

        if ($userLevel < $requiredLevel) {
            return response()->json([
                'message' => 'Insufficient role. Required: ' . $minimumRole,
            ], 403);
        }

        return $next($request);
    }
}
