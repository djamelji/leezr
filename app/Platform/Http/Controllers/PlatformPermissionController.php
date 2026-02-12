<?php

namespace App\Platform\Http\Controllers;

use App\Platform\Models\PlatformPermission;
use Illuminate\Http\JsonResponse;

class PlatformPermissionController
{
    public function index(): JsonResponse
    {
        $permissions = PlatformPermission::orderBy('key')->get();

        return response()->json(['permissions' => $permissions]);
    }
}
