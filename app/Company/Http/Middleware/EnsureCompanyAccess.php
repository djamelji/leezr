<?php

namespace App\Company\Http\Middleware;

use App\Company\Security\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unified company access middleware.
 *
 * Usage: company.access:{ability},{key?}
 *
 * Examples:
 *   company.access:access-surface,structure
 *   company.access:use-permission,shipments.view
 *   company.access:use-module,logistics_shipments
 *   company.access:manage-structure
 */
class EnsureCompanyAccess
{
    private const ABILITY_CONTEXT_KEY = [
        'access-surface' => 'surface',
        'use-module' => 'module',
        'use-permission' => 'permission',
        'manage-structure' => null,
    ];

    public function handle(Request $request, Closure $next, string $ability, ?string $key = null): Response
    {
        $company = $request->attributes->get('company');

        if (!$company) {
            return response()->json([
                'message' => 'Company context not set.',
            ], 500);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Authentication required.',
            ], 401);
        }

        $contextKey = self::ABILITY_CONTEXT_KEY[$ability] ?? null;
        $context = $contextKey && $key ? [$contextKey => $key] : [];

        if (!CompanyAccess::can($user, $company, $ability, $context)) {
            $message = match ($ability) {
                'access-surface' => 'Access restricted to management roles.',
                'use-module' => 'Module is not active for this company.',
                'use-permission' => "Permission required: {$key}",
                'manage-structure' => 'Administrative role required.',
                default => 'Access denied.',
            };

            return response()->json(['message' => $message], 403);
        }

        return $next($request);
    }
}
