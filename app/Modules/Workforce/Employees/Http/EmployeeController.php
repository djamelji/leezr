<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Data\CreateEmployeeData;
use App\Modules\Workforce\ReadModels\EmployeeReadModel;
use App\Modules\Workforce\UseCases\CreateEmployeeUseCase;
use App\Modules\Workforce\UseCases\TerminateEmployeeUseCase;
use App\Modules\Workforce\Security\WorkforceAccess;
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
            canSeeCompensation: WorkforceAccess::canReadCompensation($request),
            departmentId: $request->filled('department_id') ? (int) $request->query('department_id') : null,
            jobRoleId: $request->filled('job_role_id') ? (int) $request->query('job_role_id') : null,
            managerId: $request->filled('manager_id') ? (int) $request->query('manager_id') : null,
            contractType: $request->query('contract_type'),
            // W-G2 : scope manager — un non-RH ne voit que lui-même + ses rapports directs.
            visibleIds: $this->visibleIds($request),
        );

        return response()->json($employees);
    }

    /** W-G2 — scope de visibilité liste/export : null = vue globale (RH), sinon soi + rapports directs. */
    private function visibleIds(Request $request): ?array
    {
        return WorkforceAccess::hasViewAll($request, 'employee')
            ? null
            : WorkforceAccess::visibleEmployeeIds($request, 'employee');
    }

    /** W-G2 — filtres actifs communs liste/export. */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'department_id' => $request->filled('department_id') ? (int) $request->query('department_id') : null,
            'job_role_id' => $request->filled('job_role_id') ? (int) $request->query('job_role_id') : null,
            'manager_id' => $request->filled('manager_id') ? (int) $request->query('manager_id') : null,
            'contract_type' => $request->query('contract_type'),
        ];
    }

    // ── Export CSV (W-G2) — filtres actifs + scope + aucune donnée sensible ──
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $companyId = $request->attributes->get('company_id');

        return app(\App\Modules\Workforce\Employees\Services\EmployeeExportService::class)
            ->streamCsv($companyId, $this->filters($request), $this->visibleIds($request));
    }

    // ── Modèle CSV d'import (W-G2) ──
    public function importTemplate(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return app(\App\Modules\Workforce\Employees\Services\EmployeeExportService::class)->templateCsv();
    }

    // ── Import : dry-run (W-G2) — STRICTEMENT sans écriture ──
    public function importPreview(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'content' => 'required|string',
            'mapping' => 'required|array',
        ]);

        try {
            $service = app(\App\Modules\Workforce\Employees\Services\EmployeeImportService::class);
            $parsed = $service->parse($validated['content']);
            $report = $service->preview($companyId, $parsed['rows'], $validated['mapping']);

            return response()->json(['headers' => $parsed['headers'], ...$report]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Import : application (W-G2) — transactionnel, idempotent, audité ──
    public function import(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $validated = $request->validate([
            'content' => 'required|string',
            'mapping' => 'required|array',
        ]);

        try {
            $service = app(\App\Modules\Workforce\Employees\Services\EmployeeImportService::class);
            $parsed = $service->parse($validated['content']);
            $result = $service->apply($companyId, $parsed['rows'], $validated['mapping'], $actorId);

            return response()->json($result, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Actions groupées : dry-run (W-G2) ──
    public function bulkPreview(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'action' => 'required|string',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
            'target_id' => 'required|integer',
        ]);

        try {
            return response()->json(app(\App\Modules\Workforce\Employees\Services\EmployeeBulkActionService::class)->preview(
                $companyId, $validated['action'], $validated['employee_ids'], $validated['target_id'], $this->visibleIds($request),
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Actions groupées : application (W-G2) ──
    public function bulk(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $validated = $request->validate([
            'action' => 'required|string',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
            'target_id' => 'required|integer',
        ]);

        try {
            return response()->json(app(\App\Modules\Workforce\Employees\Services\EmployeeBulkActionService::class)->apply(
                $companyId, $validated['action'], $validated['employee_ids'], $validated['target_id'], $this->visibleIds($request), $actorId,
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * ADR-640 (W-C) — fiche collaborateur unifiée : synthèse composite. Scope W-D (soi / rapport direct /
     * RH), compensation masquée sans workforce.compensation_read.
     */
    public function file(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $id, 'employee'),
            403,
            'Not authorized to view this employee file.'
        );
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($id);
        if (! $employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }
        $company = \App\Core\Models\Company::withoutGlobalScopes()->findOrFail($companyId);

        return response()->json(\App\Modules\Workforce\ReadModels\EmployeeFileReadModel::overview(
            $company, $employee, WorkforceAccess::canReadCompensation($request),
        ));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $employee = EmployeeReadModel::detail($companyId, $id, WorkforceAccess::canReadCompensation($request));

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
