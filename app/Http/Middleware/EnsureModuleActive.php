<?php

namespace App\Http\Middleware;

use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unified middleware that ensures a module is active.
 *
 * Admin scope: checks PlatformModule.is_enabled_globally (no company context needed).
 * Company scope: checks company-level activation (requires SetCompanyContext).
 *
 * Usage: ->middleware('module.active:platform.companies')
 */
class EnsureModuleActive
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return response()->json([
                'message' => 'Unknown module.',
                'module' => $moduleKey,
            ], 404);
        }

        // Admin scope: global toggle only
        if ($manifest->scope === 'admin') {
            if (!ModuleGate::isEnabledGlobally($moduleKey)) {
                return response()->json([
                    'message' => 'Module is not active.',
                    'module' => $moduleKey,
                ], 403);
            }

            return $next($request);
        }

        // Company scope: existing logic
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
