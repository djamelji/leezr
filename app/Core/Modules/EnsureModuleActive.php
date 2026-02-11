<?php

namespace App\Core\Modules;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that ensures a module is active for the current company.
 * Usage: ->middleware('module.active:core.members')
 * Requires SetCompanyContext to have run first (company in request attributes).
 */
class EnsureModuleActive
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $company = $request->attributes->get('company');

        if (!$company) {
            return response()->json([
                'message' => 'Company context not set.',
            ], 500);
        }

        if (!ModuleGate::isActive($company, $moduleKey)) {
            return response()->json([
                'message' => 'Module is not active for this company.',
                'module' => $moduleKey,
            ], 403);
        }

        return $next($request);
    }
}
