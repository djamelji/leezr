<?php

namespace App\Modules\Platform\Roles\Http;

use App\Core\RBAC\PermissionCatalogBuilder;
use Illuminate\Http\JsonResponse;

class PermissionController
{
    public function index(): JsonResponse
    {
        return response()->json(
            PermissionCatalogBuilder::build('admin')
        );
    }
}
