<?php

namespace App\Modules\Platform\Roles\Http;

use App\Platform\Models\PlatformRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController
{
    public function index(): JsonResponse
    {
        $roles = PlatformRole::withCount('users')
            ->with('permissions')
            ->orderBy('key')
            ->get();

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:50|unique:platform_roles,key',
            'name' => 'required|string|max:100',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:platform_permissions,id',
        ]);

        $role = PlatformRole::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
        ]);

        if (isset($validated['permissions'])) {
            $role->permissions()->sync($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role created.',
            'role' => $role->loadCount('users')->load('permissions'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = PlatformRole::findOrFail($id);

        if ($role->key === 'super_admin' && $request->has('permissions')) {
            return response()->json([
                'message' => 'Cannot modify super_admin permissions — they are structural.',
            ], 409);
        }

        $validated = $request->validate([
            'key' => 'sometimes|string|max:50|unique:platform_roles,key,' . $role->id,
            'name' => 'sometimes|string|max:100',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:platform_permissions,id',
        ]);

        $fields = array_intersect_key($validated, array_flip(['key', 'name']));
        if (!empty($fields)) {
            $role->update($fields);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->permissions()->sync($validated['permissions']);
        }

        return response()->json([
            'message' => 'Role updated.',
            'role' => $role->loadCount('users')->load('permissions'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = PlatformRole::withCount('users')->findOrFail($id);

        if ($role->key === 'super_admin') {
            return response()->json([
                'message' => 'Cannot delete the super_admin role — it is structural.',
            ], 409);
        }

        if ($role->users_count > 0) {
            return response()->json([
                'message' => "Cannot delete role '{$role->name}' — {$role->users_count} user(s) attached.",
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted.',
        ]);
    }
}
