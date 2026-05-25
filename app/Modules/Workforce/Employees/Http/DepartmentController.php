<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\Department;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\UseCases\CreateDepartmentUseCase;
use App\Modules\Workforce\UseCases\DeleteDepartmentUseCase;
use App\Modules\Workforce\UseCases\UpdateDepartmentUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $departments = Department::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['manager:id,first_name,last_name', 'children'])
            ->withCount('employees')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($departments);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:workforce_departments,id',
            'manager_id' => 'nullable|integer|exists:workforce_employees,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $department = app(CreateDepartmentUseCase::class)->execute(
            companyId: $companyId,
            name: $validated['name'],
            parentId: $validated['parent_id'] ?? null,
            managerId: $validated['manager_id'] ?? null,
            sortOrder: (int) ($validated['sort_order'] ?? 0),
        );

        return response()->json($department->load('manager:id,first_name,last_name'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $department = Department::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'parent_id' => 'nullable|integer|exists:workforce_departments,id',
            'manager_id' => 'nullable|integer|exists:workforce_employees,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $department = app(UpdateDepartmentUseCase::class)->execute($department, $validated);

        return response()->json($department->load('manager:id,first_name,last_name'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $department = Department::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            app(DeleteDepartmentUseCase::class)->execute($department);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }
}
