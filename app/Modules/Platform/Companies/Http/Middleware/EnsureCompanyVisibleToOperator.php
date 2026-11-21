<?php

namespace App\Modules\Platform\Companies\Http\Middleware;

use App\Modules\Platform\Companies\Creation\PlatformCompanyCreation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-834 — `platform.company.scope:<permission>` on a `/companies/{id}…` route.
 * Allowed when the Platform user holds <permission> (global right on every company), OR holds
 * `create_companies` and created THIS company himself. `create_companies` never implies `view_companies` or
 * `manage_companies` on the other companies.
 */
class EnsureCompanyVisibleToOperator
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('platform');
        if ($user && $user->hasPermission($permission)) {
            return $next($request);
        }
        $companyId = (int) $request->route('id');
        if ($user && $companyId > 0 && $user->hasPermission('create_companies')
            && PlatformCompanyCreation::where('company_id', $companyId)->where('platform_user_id', $user->id)->exists()) {
            return $next($request);
        }

        return response()->json(['message' => 'Permission required: '.$permission.' (or creator of this company)'], 403);
    }
}
