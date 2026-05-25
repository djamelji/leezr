<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Http\Controllers\Controller;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\UseCases\CreateContractUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request, int $employeeId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        $contracts = EmploymentContract::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->with(['conventionCollective:id,idcc,short_name', 'compensationPlans'])
            ->orderByDesc('start_date')
            ->get();

        return response()->json($contracts);
    }

    public function store(Request $request, int $employeeId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        $validated = $request->validate([
            'contract_type' => 'required|in:cdi,cdd,stage,alternance,freelance',
            'work_model_key' => 'required|in:horaire,forfait_jours,forfait_heures',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'probation_end_date' => 'nullable|date|after:start_date',
            'weekly_hours' => 'nullable|numeric|min:0|max:99',
            'base_salary_cents' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'pay_frequency' => 'nullable|in:monthly,biweekly,weekly',
            'overtime_rate_bps' => 'nullable|integer|min:0',
            'convention_collective_id' => 'nullable|integer|exists:convention_collectives,id',
            'compensation_type' => 'nullable|in:monthly,hourly,daily',
            'hourly_rate_cents' => 'nullable|integer|min:0',
            'daily_rate_cents' => 'nullable|integer|min:0',
        ]);

        $contract = app(CreateContractUseCase::class)->execute(
            employee: $employee,
            contractType: $validated['contract_type'],
            workModelKey: $validated['work_model_key'],
            startDate: $validated['start_date'],
            weeklyHours: (float) ($validated['weekly_hours'] ?? 35.00),
            endDate: $validated['end_date'] ?? null,
            probationEndDate: $validated['probation_end_date'] ?? null,
            baseSalaryCents: $validated['base_salary_cents'] ?? null,
            currency: $validated['currency'] ?? 'EUR',
            payFrequency: $validated['pay_frequency'] ?? 'monthly',
            overtimeRateBps: (int) ($validated['overtime_rate_bps'] ?? 2500),
            conventionCollectiveId: isset($validated['convention_collective_id'])
                ? (int) $validated['convention_collective_id']
                : null,
            compensationType: $validated['compensation_type'] ?? 'monthly',
            hourlyRateCents: isset($validated['hourly_rate_cents']) ? (int) $validated['hourly_rate_cents'] : null,
            dailyRateCents: isset($validated['daily_rate_cents']) ? (int) $validated['daily_rate_cents'] : null,
        );

        $contract->load(['conventionCollective:id,idcc,short_name', 'compensationPlans']);

        return response()->json($contract, 201);
    }

    public function activate(Request $request, int $employeeId, int $contractId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        $contract = EmploymentContract::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->findOrFail($contractId);

        if (! $contract->canTransitionTo(EmploymentContract::STATUS_ACTIVE)) {
            return response()->json(['message' => 'Cannot activate contract in current status'], 422);
        }

        $contract->transitionTo(EmploymentContract::STATUS_ACTIVE);
        $contract->update(['status' => $contract->status]);

        return response()->json($contract->fresh());
    }

    public function conventions(Request $request): JsonResponse
    {
        $conventions = ConventionCollective::active()
            ->orderBy('short_name')
            ->get(['id', 'idcc', 'short_name', 'full_name']);

        return response()->json($conventions);
    }
}
