<?php

namespace App\Company\Http\Controllers;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
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
        $modules = collect(ModuleRegistry::definitions());

        $moduleNames = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->name]);
        $moduleDescriptions = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->description]);

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

        // Build module list with bundles (capabilities)
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

        $fields = array_intersect_key($validated, array_flip(['name', 'is_administrative']));
        if (!empty($fields)) {
            $role->update($fields);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissionsSafe($validated['permissions']);
        }

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

        return response()->json([
            'message' => 'Role deleted.',
        ]);
    }
}
