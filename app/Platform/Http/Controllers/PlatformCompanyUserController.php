<?php

namespace App\Platform\Http\Controllers;

use App\Core\Models\User;
use Illuminate\Http\JsonResponse;

class PlatformCompanyUserController
{
    public function index(): JsonResponse
    {
        $users = User::with('companies')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($users);
    }
}
