<?php

namespace App\Modules\Infrastructure\Navigation\Http;

use App\Core\Navigation\NavBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavController
{
    /**
     * GET /api/platform/nav
     *
     * Backend filters: activation + permissions.
     * Super-admin: null permissions = bypass (sees all).
     */
    public function platform(Request $request): JsonResponse
    {
        $user = $request->user('platform');
        $user->load('roles.permissions');

        $isSuperAdmin = $user->roles->contains(fn ($r) => $r->key === 'super_admin');

        $permissions = $isSuperAdmin
            ? null
            : $user->roles->flatMap->permissions->pluck('key')->unique()->values()->all();

        return response()->json([
            'groups' => NavBuilder::forAdmin($permissions),
        ]);
    }

    /**
     * GET /api/nav (company-scoped)
     *
     * Backend filters: activation + plan + jobdomain + permissions + roleLevel.
     * Company context set by SetCompanyContext middleware.
     *
     * Permission semantics:
     *   - Owner: null (bypass — sees everything)
     *   - Administrative: actual permissions from CompanyRole (surface=management, filtered by permissions)
     *   - Operational: actual permissions from CompanyRole (surface=operational, filtered by permissions)
     */
    public function company(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        $membership = $user->membershipFor($company);

        $isOwner = $user->isOwnerOf($company);
        $roleLevel = ($isOwner || $membership->isAdmin()) ? 'management' : 'operational';

        // Owner = null (bypass). Everyone else = actual permissions from CompanyRole.
        if ($isOwner) {
            $permissions = null;
        } else {
            $membership->loadMissing('companyRole.permissions');
            $permissions = $membership->companyRole
                ? $membership->companyRole->permissions->pluck('key')->unique()->values()->all()
                : [];
        }

        return response()->json([
            'groups' => NavBuilder::forCompany($company, $permissions, $roleLevel),
        ]);
    }
}
