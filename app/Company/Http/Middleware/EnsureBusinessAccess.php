<?php

namespace App\Company\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricted-access gate (product decision, audit 2026-10).
 *
 * When a company's subscription no longer grants business access — an expired
 * trial or a terminated subscription — the company KEEPS:
 *   - read access to all its data (any GET/HEAD/OPTIONS request), and
 *   - subscription management & payment (billing/* + plan change),
 * but can no longer perform business WRITE operations (create/update/delete in
 * business modules).
 *
 * The decision is taken at request time from the subscription state
 * (Company::hasBusinessAccess), so a stopped scheduler cannot leave an expired
 * trial fully active. Reactivation is automatic and idempotent: once a valid
 * subscription exists again the gate opens with no extra step.
 *
 * This is intentionally separate from Company suspension, which is enforced in
 * SetCompanyContext (company.status = 'suspended' → 403 for everything).
 */
class EnsureBusinessAccess
{
    /**
     * Mutating verbs. Reads are always allowed in restricted mode.
     */
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Path prefixes (under the `api/` group) that stay writable in restricted
     * mode: billing/payment/subscription management (so the company can pay and
     * regularize), plan change, and non-business account/UX actions.
     */
    private const ALLOWED_MUTATION_PREFIXES = [
        'api/billing/',          // checkout, invoices, saved cards, pay-now, plan-change, cancel…
        'api/company/plan',      // upgrade/downgrade plan to regularize
        'api/dashboard/onboarding', // dismiss/reopen onboarding (UX)
        'api/dashboard/layout',  // personal dashboard layout (UX)
        'api/dashboard/widgets', // widget data resolution (read-like POST)
        'api/notifications',     // mark read / dismiss (UX)
        'api/2fa',               // security
        'api/theme',             // theme preference (UX)
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Reads are always permitted — the company retains full visibility.
        if (! in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        // Billing/payment/account actions stay available so the company can pay.
        if ($this->isAllowedMutation($request)) {
            return $next($request);
        }

        $company = $request->attributes->get('company');

        if ($company !== null && ! $company->hasBusinessAccess()) {
            return response()->json([
                'message' => 'Your subscription has expired. Business operations are paused — '
                    .'you can still view your data and renew your subscription.',
                'code' => 'subscription_required',
                'restricted_access' => true,
            ], 402);
        }

        return $next($request);
    }

    private function isAllowedMutation(Request $request): bool
    {
        $path = $request->path();

        foreach (self::ALLOWED_MUTATION_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
