<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Employees\Services\EmployeeAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — access of an employee to their company space (W-HR-UX-3, SECURITY-1), moved verbatim out of
 * EmployeeController : status, grant, resend, suspend, restore, revoke. Same routes, middleware and permissions
 * (members.view / members.invite / members.manage) ; the work stays in EmployeeAccessService (Members mechanism).
 */
class EmployeeAccessController extends Controller
{
    // ── W-HR-UX-3 : accès à l'espace collaborateur DEPUIS la fiche (réutilise le mécanisme Members) ──

    public function accessStatus(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);

        return response()->json(app(EmployeeAccessService::class)->status($company, $employee));
    }

    public function grantAccess(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'company_role_id' => 'nullable|integer',
        ]);

        $status = app(EmployeeAccessService::class)->grant(
            $company, $employee, $data['email'], $data['company_role_id'] ?? null, (int) $request->user()->id,
        );

        return response()->json($status);
    }

    public function resendAccess(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);

        return response()->json(app(EmployeeAccessService::class)->resendInvitation($company, $employee));
    }

    // ── SECURITY-1: suspend / restore / revoke company access ──
    public function suspendAccess(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);
        $reason = $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null;

        return response()->json(app(EmployeeAccessService::class)->suspend($company, $employee, (int) $request->user()->id, $reason));
    }

    public function restoreAccess(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);

        return response()->json(app(EmployeeAccessService::class)->restore($company, $employee, (int) $request->user()->id));
    }

    public function revokeAccess(Request $request, int $id): JsonResponse
    {
        [$company, $employee] = $this->target($request, $id);
        $reason = $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null;

        return response()->json(app(EmployeeAccessService::class)->revoke($company, $employee, (int) $request->user()->id, $reason));
    }

    /** the current company and one of its employees (tenant-explicit) */
    private function target(Request $request, int $id): array
    {
        $company = $request->attributes->get('company') ?? Company::withoutGlobalScopes()->findOrFail($request->attributes->get('company_id'));

        return [$company, Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($id)];
    }
}
