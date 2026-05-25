<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Data\CreateEmployeeData;
use App\Modules\Workforce\ReadModels\EmployeeReadModel;
use App\Modules\Workforce\UseCases\CreateEmployeeUseCase;
use App\Modules\Workforce\UseCases\TerminateEmployeeUseCase;
use App\Core\Workforce\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $employees = EmployeeReadModel::list(
            companyId: $companyId,
            search: $request->query('search'),
            status: $request->query('status'),
            perPage: (int) $request->query('per_page', 15),
            sortBy: $request->query('sort_by', 'last_name'),
            sortDir: $request->query('sort_dir', 'asc'),
        );

        return response()->json($employees);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = EmployeeReadModel::detail($companyId, $id);

        if (! $employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json($employee);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'employee_number' => 'nullable|string|max:50',
            'hire_date' => 'required|date',
            'department_id' => 'nullable|integer|exists:workforce_departments,id',
            'job_role_id' => 'nullable|integer|exists:workforce_job_roles,id',
            'manager_id' => 'nullable|integer|exists:workforce_employees,id',
        ]);

        $data = new CreateEmployeeData(
            companyId: $companyId,
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            hireDate: $validated['hire_date'],
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            employeeNumber: $validated['employee_number'] ?? null,
            departmentId: $validated['department_id'] ?? null,
            jobRoleId: $validated['job_role_id'] ?? null,
            managerId: $validated['manager_id'] ?? null,
        );

        $employee = app(CreateEmployeeUseCase::class)->execute($data);

        return response()->json($employee, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'employee_number' => 'sometimes|nullable|string|max:50',
            'department_id' => 'sometimes|nullable|integer|exists:workforce_departments,id',
            'job_role_id' => 'sometimes|nullable|integer|exists:workforce_job_roles,id',
            'manager_id' => 'sometimes|nullable|integer|exists:workforce_employees,id',
        ]);

        $employee->update($validated);

        return response()->json($employee);
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'termination_date' => 'nullable|date',
        ]);

        $employee = app(TerminateEmployeeUseCase::class)->execute(
            $employee,
            $validated['reason'],
            isset($validated['termination_date']) ? Carbon::parse($validated['termination_date']) : null,
        );

        return response()->json($employee);
    }

    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json([
            'counts' => EmployeeReadModel::countByStatus($companyId),
        ]);
    }
}
