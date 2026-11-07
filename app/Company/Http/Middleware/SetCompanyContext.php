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

        // PRODUCT-SECURITY-1 (ADR-688): a suspended membership blocks ALL company access
        // SERVER-SIDE (every endpoint, SSE included) — effective on the next request, since
        // auth is pure cookie SPA and the membership is re-queried each request. Owner cannot
        // be suspended (guarded at the action), so this never locks the company's owner out.
        if ($membership && $membership->isSuspended()) {
            return response()->json([
                'message' => 'Your access to this company has been suspended.',
                'code' => 'membership_suspended',
            ], 403);
        }

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
        $request->attributes->set('company_id', $company->id);

        // ADR-432: Bind company to container for CompanyScope global scope
        app()->instance('company.context', $company);

        return $next($request);
    }

    /**
     * ADR-432: Clear company context after request to prevent bleed
     * between requests (important for tests and queue workers).
     */
    public function terminate(Request $request, Response $response): void
    {
        app()->forgetInstance('company.context');
    }

    /**
     * ADR-257 / P0-COMMERCE (ADR-734): routes a SUSPENDED company must still reach
     * so it can regularize and recover access — never business features.
     *
     *   - api/billing/      : see what is owed, pay, add a card, change plan (regularization)
     *   - api/company/plan  : upgrade/downgrade to a payable plan to regularize
     *   - api/support/      : contact support about the suspension
     *
     * Administrative reactivation runs on the Platform guard (routes/platform.php),
     * not this company stack. Everything else stays 403 while suspended.
     *
     * This deliberately mirrors EnsureBusinessAccess::ALLOWED_MUTATION_PREFIXES for
     * billing/plan so the two gates agree on the regularization surface.
     */
    private const SUSPENDED_BYPASS_PREFIXES = [
        'api/billing/',
        'api/company/plan',
        'api/support/',
    ];

    private static function isSuspendedBypass(Request $request): bool
    {
        $path = $request->path();

        foreach (self::SUSPENDED_BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
