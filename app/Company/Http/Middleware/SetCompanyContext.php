<?php

namespace App\Company\Http\Middleware;

use App\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SetCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // ADR-125: Accept company ID from query parameter as fallback
        // (EventSource API does not support custom headers)
        $companyId = $request->header('X-Company-Id') ?? $request->query('company_id');

        if (!$companyId) {
            return response()->json([
                'message' => 'X-Company-Id header is required.',
            ], 400);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.',
            ], 404);
        }

        if ($company->isSuspended() && !self::isSuspendedBypass($request)) {
            return response()->json([
                'message' => 'This company is currently suspended.',
            ], 403);
        }

        $user = $request->user();

        if (!$user->isMemberOf($company)) {
            return response()->json([
                'message' => 'You are not a member of this company.',
            ], 403);
        }

        // Runtime invariant: non-owner memberships MUST have a CompanyRole
        $membership = $user->membershipFor($company);

        if ($membership && !$membership->isOwner() && $membership->company_role_id === null) {
            Log::critical('RBAC invariant violation: non-owner membership without CompanyRole', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'membership_id' => $membership->id,
            ]);

            return response()->json([
                'message' => 'Account configuration error. Contact your administrator.',
            ], 403);
        }

        $request->merge(['company' => $company]);
        $request->attributes->set('company', $company);

        // ADR-432: Bind company to container for CompanyScope global scope
        app()->instance('company.context', $company);

        return $next($request);
    }

    /**
     * ADR-257: Billing payment routes must remain accessible for suspended
     * companies — that's how they pay outstanding invoices to reactivate.
     */
    /**
     * ADR-432: Clear company context after request to prevent bleed
     * between requests (important for tests and queue workers).
     */
    public function terminate(Request $request, Response $response): void
    {
        app()->forgetInstance('company.context');
    }

    private static function isSuspendedBypass(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'api/billing/invoices/outstanding')
            || str_starts_with($path, 'api/billing/invoices/pay');
    }
}
