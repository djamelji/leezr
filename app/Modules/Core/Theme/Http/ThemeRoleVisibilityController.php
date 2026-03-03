<?php

namespace App\Modules\Core\Theme\Http;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeRoleVisibilityController
{
    /**
     * GET /api/company/theme/role-visibility
     *
     * Returns all company roles with their theme toggle visibility status.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'roles' => $this->buildRoleList($company->id),
        ]);
    }

    /**
     * PUT /api/company/theme/role-visibility
     *
     * Toggle theme.view permission on/off per role.
     * Accepts: { visibility: { roleId: bool, ... } }
     */
    public function update(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'visibility' => 'required|array',
            'visibility.*' => 'boolean',
        ]);

        $themeViewPerm = CompanyPermission::where('key', 'theme.view')->first();

        if (!$themeViewPerm) {
            return response()->json(['message' => 'Permission theme.view not found.'], 500);
        }

        // Verify all role IDs belong to this company
        $roleIds = array_map('intval', array_keys($validated['visibility']));
        $companyRoles = CompanyRole::where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->with('permissions')
            ->get()
            ->keyBy('id');

        if ($companyRoles->count() !== count($roleIds)) {
            return response()->json(['message' => 'One or more roles not found.'], 422);
        }

        $before = [];
        $after = [];

        foreach ($validated['visibility'] as $roleId => $visible) {
            $role = $companyRoles->get((int) $roleId);
            $hadPermission = $role->permissions->contains('id', $themeViewPerm->id);

            $before[$role->key] = $hadPermission;
            $after[$role->key] = (bool) $visible;

            if ($visible && !$hadPermission) {
                $role->permissions()->attach($themeViewPerm->id);
            } elseif (!$visible && $hadPermission) {
                $role->permissions()->detach($themeViewPerm->id);
            }
        }

        // Audit only if something changed
        if ($before !== $after) {
            app(AuditLogger::class)->logCompany(
                $company->id,
                AuditAction::THEME_VISIBILITY_UPDATED,
                'theme',
                'role-visibility',
                ['diffBefore' => $before, 'diffAfter' => $after],
            );

            // Publish rbac.changed so nav/widgets refresh
            app(RealtimePublisher::class)->publish(
                EventEnvelope::invalidation('rbac.changed', $company->id, [
                    'action' => 'theme.visibility_updated',
                ])
            );
        }

        return response()->json([
            'message' => 'Theme visibility updated.',
            'roles' => $this->buildRoleList($company->id),
        ]);
    }

    /**
     * Build the role list with visibility status.
     */
    private function buildRoleList(int $companyId): array
    {
        $themeViewPerm = CompanyPermission::where('key', 'theme.view')->first();
        $themeViewId = $themeViewPerm?->id;

        return CompanyRole::where('company_id', $companyId)
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyRole $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'key' => $role->key,
                'visible' => $themeViewId
                    ? $role->permissions->contains('id', $themeViewId)
                    : false,
            ])
            ->values()
            ->all();
    }
}
