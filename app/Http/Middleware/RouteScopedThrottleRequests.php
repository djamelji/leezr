<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * ADR-830 — an UNNAMED `throttle:N,M` limits THIS route only.
 *
 * Laravel keys unnamed limiters by user (or IP) alone : every `throttle:N,M` route shared ONE counter,
 * so the periodic version check of a few tabs exhausted /public/plans, theme, world, uploads… (429 on
 * billing pages). The limit values are unchanged ; only the bucket is per route (method + URI).
 * Named limiters (`throttle:N,M,name`, `throttle:<RateLimiter name>`) keep their explicit, shared budget.
 */
class RouteScopedThrottleRequests extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // Named RateLimiter (`throttle:api`) : the parent detects it by its argument COUNT — forward untouched.
        if (func_num_args() === 3 || $prefix !== '' || ! is_numeric($maxAttempts) || ! ($route = $request->route())) {
            return parent::handle(...func_get_args());
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes,
            'route:'.sha1(implode('|', $route->methods()).'|'.$route->uri()).':');
    }
}
