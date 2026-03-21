<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyUserController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('companies')
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }
}
