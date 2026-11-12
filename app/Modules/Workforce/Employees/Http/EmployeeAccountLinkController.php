<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Employee;
use App\Modules\Workforce\UseCases\AttachEmployeeToMemberUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ADR-768 (M7 LOT 2) — explicit account ↔ HR record bridge (see AttachEmployeeToMemberUseCase). */
class EmployeeAccountLinkController
{
    public function __construct(private readonly AttachEmployeeToMemberUseCase $attach)
    {
    }

    public function candidates(Request $request): JsonResponse
    {
        return response()->json(['candidates' => $this->attach->candidates($request->attributes->get('company'))]);
    }

    public function createForMember(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'user_id' => 'required|integer',
            'hire_date' => 'required|date',
            'employee_number' => 'nullable|string|max:50',
            'department_id' => ['nullable', 'integer', Rule::exists('workforce_departments', 'id')->where('company_id', $company->id)],
            'job_role_id' => ['nullable', 'integer', Rule::exists('workforce_job_roles', 'id')->where('company_id', $company->id)],
            'manager_id' => ['nullable', 'integer', Rule::exists('workforce_employees', 'id')->where('company_id', $company->id)],
        ]);

        $employee = $this->attach->createForMember($company, (int) $data['user_id'], $data, $request->user());

        return response()->json($employee, 201);
    }

    public function linkAccount(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($id);
        $data = $request->validate(['user_id' => 'required|integer']);

        $employee = $this->attach->linkAccount($company, $employee, (int) $data['user_id'], $request->user());

        return response()->json(['id' => $employee->id, 'user_id' => $employee->user_id]);
    }
}
