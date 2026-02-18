<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Models\User;
use Illuminate\Http\JsonResponse;

class CompanyUserController
{
    public function index(): JsonResponse
    {
        $users = User::with('companies')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($users);
    }
}
