<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\JobRole;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\PayrollConfidentiality;
use App\Modules\Workforce\UseCases\CreateJobRoleUseCase;
use App\Modules\Workforce\UseCases\DeleteJobRoleUseCase;
use App\Modules\Workforce\UseCases\UpdateJobRoleUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $roles = JobRole::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('department:id,name')
            ->withCount('employees')
            ->orderBy('title')
            ->get();

        // P2 (ADR-684): job roles carry default_hourly_rate_cents (reference comp) →
        // mask server-side for viewers without compensation_read (was Vue-only).
        return response()->json(PayrollConfidentiality::scrub($roles, PayrollConfidentiality::canRead($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('workforce_departments', 'id')->where('company_id', $request->attributes->get('company_id'))], // ADR-817 : service de la même société
            'level' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'default_hourly_rate_cents' => 'nullable|integer|min:0',
        ]);

        $role = app(CreateJobRoleUseCase::class)->execute(
            companyId: $companyId,
            title: $validated['title'],
            departmentId: $validated['department_id'] ?? null,
            level: $validated['level'] ?? null,
            description: $validated['description'] ?? null,
            defaultHourlyRateCents: isset($validated['default_hourly_rate_cents']) ? (int) $validated['default_hourly_rate_cents'] : null,
        );

        return response()->json($role->load('department:id,name'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $role = JobRole::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'department_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('workforce_departments', 'id')->where('company_id', $request->attributes->get('company_id'))], // ADR-817 : service de la même société
            'level' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'default_hourly_rate_cents' => 'nullable|integer|min:0',
            // ADR-861 : activities exercised in this position (e.g. driving) — scope the fields a module requires for them
            'activities' => 'sometimes|array',
            'activities.*' => ['string', \Illuminate\Validation\Rule::in([\App\Core\Fields\TagDictionary::DRIVING, \App\Core\Fields\TagDictionary::DISPATCHING, \App\Core\Fields\TagDictionary::MANAGEMENT])],
        ]);
        if (array_key_exists('activities', $validated)) {
            $validated['metadata'] = array_merge((array) ($role->metadata ?? []), ['activities' => array_values(array_unique($validated['activities']))]);
            unset($validated['activities']);
        }

        $role = app(UpdateJobRoleUseCase::class)->execute($role, $validated);
        \App\Core\Fields\FunctionActivity::flush((int) $companyId);

        return response()->json($role->load('department:id,name'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $role = JobRole::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            app(DeleteJobRoleUseCase::class)->execute($role);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }
}
