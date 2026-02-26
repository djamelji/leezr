<?php

namespace App\Modules\Core\Roles\Http;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Core\Security\SecurityDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $roles = CompanyRole::where('company_id', $company->id)
            ->withCount('memberships')
            ->with('permissions')
            ->orderBy('key')
            ->get();

        return response()->json(['roles' => $roles]);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $modules = collect(ModuleRegistry::forScope('company'));

        $moduleNames = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->name]);
        $moduleDescriptions = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->description]);
        $moduleIcons = $modules->mapWithKeys(fn ($m, $key) => [
            $key => collect($m->capabilities->navItems)->first()['icon'] ?? $m->iconRef,
        ]);

        // Build hint lookup from ModuleRegistry permission definitions
        $hints = [];
        foreach ($modules as $modKey => $manifest) {
            foreach ($manifest->permissions as $perm) {
                if (isset($perm['hint'])) {
                    $hints[$perm['key']] = $perm['hint'];
                }
            }
        }

        $permissions = CompanyPermission::orderBy('module_key')
            ->orderBy('key')
            ->get(['id', 'key', 'label', 'module_key', 'is_admin'])
            ->map(function ($p) use ($company, $moduleNames, $moduleDescriptions, $hints) {
                $isCore = str_starts_with($p->module_key, 'core.');

                return array_merge($p->toArray(), [
                    'module_name' => $moduleNames[$p->module_key] ?? $p->module_key,
                    'module_description' => $moduleDescriptions[$p->module_key] ?? '',
                    'hint' => $hints[$p->key] ?? '',
                    'module_active' => $isCore || ModuleGate::isActive($company, $p->module_key),
                ]);
            });

        // Build key→id lookup for resolving bundles
        $keyToId = $permissions->pluck('id', 'key');

        // Build module list with bundles (capabilities).
        // Only include bundles that resolve to at least one company permission.
        $moduleList = [];
        foreach ($modules as $modKey => $manifest) {
            $isCore = str_starts_with($modKey, 'core.');
            $isActive = $isCore || ModuleGate::isActive($company, $modKey);

            $bundles = [];
            foreach ($manifest->bundles as $bundle) {
                $permissionIds = collect($bundle['permissions'])
                    ->map(fn ($key) => $keyToId[$key] ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if (empty($permissionIds)) {
                    continue;
                }

                $bundles[] = [
                    'key' => $bundle['key'],
                    'label' => $bundle['label'],
                    'hint' => $bundle['hint'] ?? '',
                    'is_admin' => $bundle['is_admin'] ?? false,
                    'permissions' => $bundle['permissions'],
                    'permission_ids' => $permissionIds,
                ];
            }

            $moduleList[] = [
                'module_key' => $modKey,
                'module_name' => $moduleNames[$modKey] ?? $modKey,
                'module_description' => $moduleDescriptions[$modKey] ?? '',
                'module_icon' => $moduleIcons[$modKey] ?? 'tabler-puzzle',
                'module_active' => $isActive,
                'is_core' => $isCore,
                'capabilities' => $bundles,
            ];
        }

        return response()->json([
            'permissions' => $permissions,
            'modules' => $moduleList,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'is_administrative' => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:company_permissions,id',
        ]);

        // Auto-generate key from name with collision handling
        $baseKey = Str::slug($validated['name'], '_');
        $key = $baseKey;
        $suffix = 2;

        while (CompanyRole::where('company_id', $company->id)->where('key', $key)->exists()) {
            $key = $baseKey.'_'.$suffix;
            $suffix++;
        }

        $role = CompanyRole::create([
            'company_id' => $company->id,
            'key' => $key,
            'name' => $validated['name'],
            'is_administrative' => $validated['is_administrative'] ?? false,
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissionsSafe($validated['permissions']);
        }

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('rbac.changed', $company->id, ['action' => 'role.created', 'role_id' => $role->id])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::ROLE_CREATED, 'role', (string) $role->id, [
            'diffAfter' => $role->toArray(),
        ]);

        // ADR-129: detect mass role changes
        SecurityDetector::check('mass.role_changes', "user:{$request->user()->id}", $company->id, $request->user()->id);

        return response()->json([
            'message' => 'Role created.',
            'role' => $role->loadCount('memberships')->load('permissions'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $role = CompanyRole::where('company_id', $company->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_administrative' => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:company_permissions,id',
        ]);

        $wasAdministrative = $role->is_administrative;

        $fields = array_intersect_key($validated, array_flip(['name', 'is_administrative']));
        if (!empty($fields)) {
            $role->update($fields);
        }

        // Invariant: operational role cannot have admin permissions.
        // When transitioning management→operational, strip admin perms
        // even if the client didn't send a permissions array.
        $transitionedToOperational = $wasAdministrative
            && isset($validated['is_administrative'])
            && !$validated['is_administrative'];

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissionsSafe($validated['permissions']);
        } elseif ($transitionedToOperational) {
            $currentIds = $role->permissions()->pluck('company_permissions.id')->toArray();
            $safeIds = CompanyPermission::whereIn('id', $currentIds)
                ->where('is_admin', false)
                ->pluck('id')
                ->toArray();

            $role->permissions()->sync($safeIds);
        }

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('rbac.changed', $company->id, ['action' => 'role.updated', 'role_id' => $role->id])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::ROLE_UPDATED, 'role', (string) $role->id);

        // ADR-129: detect mass role changes
        SecurityDetector::check('mass.role_changes', "user:{$request->user()->id}", $company->id, $request->user()->id);

        return response()->json([
            'message' => 'Role updated.',
            'role' => $role->loadCount('memberships')->load('permissions'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $role = CompanyRole::where('company_id', $company->id)
            ->withCount('memberships')
            ->findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'Cannot delete a system role.',
            ], 409);
        }

        if ($role->memberships_count > 0) {
            return response()->json([
                'message' => "Cannot delete role '{$role->name}' — {$role->memberships_count} member(s) attached.",
            ], 422);
        }

        $role->delete();

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('rbac.changed', $company->id, ['action' => 'role.deleted', 'role_id' => $id])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::ROLE_DELETED, 'role', (string) $id);

        // ADR-129: detect mass role changes
        SecurityDetector::check('mass.role_changes', "user:{$request->user()->id}", $company->id, $request->user()->id);

        return response()->json([
            'message' => 'Role deleted.',
        ]);
    }
}
