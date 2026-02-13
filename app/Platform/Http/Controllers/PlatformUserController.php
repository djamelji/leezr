<?php

namespace App\Platform\Http\Controllers;

use App\Core\Auth\PasswordPolicy;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

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
        $invite = $request->boolean('invite', true);

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:platform_users,email',
            'invite' => 'sometimes|boolean',
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:platform_roles,id',
        ];

        if (!$invite) {
            $rules['password'] = ['required', 'confirmed', PasswordPolicy::rules()];
        }

        $validated = $request->validate($rules);

        $user = PlatformUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $invite ? null : $validated['password'],
        ]);

        if (isset($validated['roles'])) {
            $user->roles()->sync($validated['roles']);
        }

        if ($invite) {
            $token = Password::broker('platform_users')->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        $message = $invite
            ? 'Platform user created. Invitation sent.'
            : 'Platform user created with password.';

        return response()->json([
            'message' => $message,
            'user' => $user->load('roles'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = PlatformUser::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:platform_users,email,' . $user->id,
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:platform_roles,id',
        ]);

        $fields = array_intersect_key($validated, array_flip(['name', 'email']));
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

    public function setPassword(Request $request, int $id): JsonResponse
    {
        $user = PlatformUser::findOrFail($id);

        $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
        ]);

        $user->forceFill([
            'password' => $request->input('password'),
        ])->save();

        return response()->json([
            'message' => 'Password set for ' . $user->name . '.',
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
