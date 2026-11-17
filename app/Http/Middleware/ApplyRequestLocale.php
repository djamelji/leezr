<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * ADR-818 (cohérence transversale) — the SPA sends its interface language (`X-Locale`) with every API call ;
 * server messages (validation, business refusals) are now returned in that language instead of always in
 * the application default. Only languages shipped in `lang/` are accepted ; without the header nothing changes.
 * Notifications to a USER keep their own language (User::preferredLocale, ADR-813) ; a message to an address
 * without account (e.g. a candidate) is rendered in the language of the person who sends it.
 */
class ApplyRequestLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = strtolower(substr((string) $request->header('X-Locale', ''), 0, 2));
        if ($locale !== '' && is_dir(lang_path($locale))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
