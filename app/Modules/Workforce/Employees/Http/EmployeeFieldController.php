<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Fields\EmployeeFieldStore;
use App\Core\Workforce\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-795 (L5) — dynamic fields of an employee record, WITH OR WITHOUT account (HR facet).
 * Read : workforce.view + employee scope (route) ; write : workforce.manage (route). Sensitive values :
 * workforce.sensitive_read or members.sensitive_read (read AND write). Tenant : the employee is always
 * resolved inside the current company (404 otherwise).
 */
class EmployeeFieldController extends Controller
{
    private function context(Request $request, int $id): array
    {
        $company = $request->attributes->get('company');
        $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($id);
        $canSensitive = $request->user()->hasCompanyPermission($company, 'workforce.sensitive_read')
            || $request->user()->hasCompanyPermission($company, 'members.sensitive_read');

        return [new EmployeeFieldStore($company), $employee, $canSensitive];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$store, $employee, $canSensitive] = $this->context($request, $id);

        return response()->json(['has_account' => $employee->user_id !== null, 'fields' => $store->read($employee, $canSensitive)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['fields' => 'required|array|min:1|max:100', 'confirm_contractual' => 'sometimes|boolean']);
        [$store, $employee, $canSensitive] = $this->context($request, $id);

        return response()->json($store->write($employee, $data['fields'], $request->user(), $canSensitive, (bool) ($data['confirm_contractual'] ?? false)));
    }
}
