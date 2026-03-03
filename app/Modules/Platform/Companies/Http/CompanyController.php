<?php

namespace App\Modules\Platform\Companies\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyEntitlements;
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
            'plan' => PlanRegistry::definitions()[CompanyEntitlements::planKey($company)] ?? null,
            'modules' => ModuleCatalogReadModel::forCompany($company),
            'incomplete_profiles_count' => CompanyUserProfileReadModel::incompleteCount($company),
        ]);
    }

    public function suspend(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'suspended']);

        app(AuditLogger::class)->logPlatform(
            AuditAction::COMPANY_SUSPENDED, 'company', (string) $company->id,
            ['diffBefore' => ['status' => 'active'], 'diffAfter' => ['status' => 'suspended']],
        );

        return response()->json([
            'message' => 'Company suspended.',
            'company' => $company,
        ]);
    }

    public function reactivate(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'active']);

        app(AuditLogger::class)->logPlatform(
            AuditAction::COMPANY_REACTIVATED, 'company', (string) $company->id,
            ['diffBefore' => ['status' => 'suspended'], 'diffAfter' => ['status' => 'active']],
        );

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
