<?php

namespace App\Modules\Platform\Roles\Http;

use App\Modules\Platform\Roles\ReadModels\PlatformRoleReadModel;
use App\Modules\Platform\Roles\UseCases\DeletePlatformRoleUseCase;
use App\Modules\Platform\Roles\UseCases\UpsertPlatformRoleUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController
{
    public function index(): JsonResponse
    {
        return response()->json(['roles' => PlatformRoleReadModel::catalog()]);
    }

    public function store(Request $request, UpsertPlatformRoleUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:50|unique:platform_roles,key',
            'name' => 'required|string|max:100',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:platform_permissions,id',
        ]);

        $role = $useCase->execute(null, $validated);

        return response()->json([
            'message' => 'Role created.',
            'role' => PlatformRoleReadModel::enrich($role),
        ], 201);
    }

    public function update(Request $request, int $id, UpsertPlatformRoleUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'sometimes|string|max:50|unique:platform_roles,key,' . $id,
            'name' => 'sometimes|string|max:100',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'integer|exists:platform_permissions,id',
        ]);

        $role = $useCase->execute($id, $validated);

        return response()->json([
            'message' => 'Role updated.',
            'role' => PlatformRoleReadModel::enrich($role),
        ]);
    }

    public function destroy(int $id, DeletePlatformRoleUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return response()->json([
            'message' => 'Role deleted.',
        ]);
    }
}
