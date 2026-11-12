<?php

namespace App\Modules\Workforce\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-767 — DATA SCOPE of a direct read of ONE employee (`{id}` / `{employeeId}` route param).
 *
 * `workforce.view` grants the ACTION (reading employee data) ; the PERIMETER is decided here by the
 * single authority `WorkforceAccess` : company-wide for an HR profile (`workforce.view` +
 * `workforce.manage`, or owner), otherwise oneself + direct reports. Same rule as the employee
 * list / export / file (W-G2, ADR-640) — a direct access by id can no longer bypass it.
 */
class EnsureEmployeeInScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $employeeId = (int) ($request->route('id') ?? $request->route('employeeId'));

        abort_unless(
            WorkforceAccess::canViewEmployeeData($request, $employeeId, 'employee'),
            403,
            'Not authorized to view this employee.'
        );

        return $next($request);
    }
}
