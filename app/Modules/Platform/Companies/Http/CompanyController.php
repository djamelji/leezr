<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Models\Company;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController
{
    public function index(): JsonResponse
    {
        $companies = Company::withCount('memberships')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($companies);
    }

    public function show(int $id): JsonResponse
    {
        $company = Company::with('jobdomains')
            ->withCount('memberships')
            ->findOrFail($id);

        return response()->json([
            'company' => $company,
            'plan' => PlanRegistry::definitions()[$company->plan_key ?? 'starter'] ?? null,
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
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

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
        ]);

        $company = Company::findOrFail($id);
        $company->update(['plan_key' => $validated['plan_key']]);

        return response()->json([
            'message' => 'Plan updated.',
            'company' => $company->loadCount('memberships'),
        ]);
    }
}
