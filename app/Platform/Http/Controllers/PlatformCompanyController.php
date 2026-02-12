<?php

namespace App\Platform\Http\Controllers;

use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;

class PlatformCompanyController
{
    public function index(): JsonResponse
    {
        $companies = Company::withCount('memberships')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($companies);
    }

    public function suspend(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'suspended']);

        return response()->json([
            'message' => 'Company suspended.',
            'company' => $company,
        ]);
    }

    public function reactivate(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'active']);

        return response()->json([
            'message' => 'Company reactivated.',
            'company' => $company,
        ]);
    }
}
