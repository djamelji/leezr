<?php

namespace App\Platform\Http\Controllers;

use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformUserController
{
    public function index(): JsonResponse
    {
        $users = PlatformUser::with('roles')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:platform_users,email',
            'password' => 'required|string|min:8',
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:platform_roles,id',
        ]);

        $user = PlatformUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if (isset($validated['roles'])) {
            $user->roles()->sync($validated['roles']);
        }

        return response()->json([
            'message' => 'Platform user created.',
            'user' => $user->load('roles'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = PlatformUser::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:platform_users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:platform_roles,id',
        ]);

        $fields = array_intersect_key($validated, array_flip(['name', 'email', 'password']));
        if (!empty($fields)) {
            $user->update($fields);
        }

        if (array_key_exists('roles', $validated)) {
            // Prevent removing super_admin from the last super_admin user
            $superAdminRole = PlatformRole::where('key', 'super_admin')->first();
            if ($superAdminRole) {
                $hadSuperAdmin = $user->roles()->where('platform_roles.id', $superAdminRole->id)->exists();
                $willHaveSuperAdmin = in_array($superAdminRole->id, $validated['roles']);

                if ($hadSuperAdmin && !$willHaveSuperAdmin) {
                    $superAdminCount = $superAdminRole->users()->count();
                    if ($superAdminCount <= 1) {
                        return response()->json([
                            'message' => 'Cannot remove super_admin role — this is the last super_admin.',
                        ], 409);
                    }
                }
            }

            $user->roles()->sync($validated['roles']);
        }

        return response()->json([
            'message' => 'Platform user updated.',
            'user' => $user->load('roles'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = PlatformUser::with('roles')->findOrFail($id);

        // Prevent deleting the last super_admin
        if ($user->hasRole('super_admin')) {
            $superAdminRole = PlatformRole::where('key', 'super_admin')->first();
            if ($superAdminRole && $superAdminRole->users()->count() <= 1) {
                return response()->json([
                    'message' => 'Cannot delete the last super_admin user.',
                ], 409);
            }
        }

        $user->delete();

        return response()->json([
            'message' => 'Platform user deleted.',
        ]);
    }

}
