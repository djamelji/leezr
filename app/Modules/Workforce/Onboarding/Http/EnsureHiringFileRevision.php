<?php

namespace App\Modules\Workforce\Onboarding\Http;

use App\Modules\Workforce\Onboarding\HiringFileReadModel;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use Closure;
use Illuminate\Http\Request;

/**
 * ADR-819 (Company 2.0) — optimistic concurrency on a hiring file. When the client sends the `revision` it
 * loaded, a write is refused (409) if the file changed meanwhile (another manager, or the candidate).
 * Without `revision` (historical clients, scripts) the behaviour is unchanged.
 */
class EnsureHiringFileRevision
{
    public function handle(Request $request, Closure $next)
    {
        $expected = $request->input('revision');
        if (is_string($expected) && $expected !== '') {
            $inv = Inv::where('company_id', $request->attributes->get('company_id'))->find((int) $request->route('id'));
            if ($inv && HiringFileReadModel::revision($inv) !== $expected) {
                return response()->json(['message' => __('onboarding.stale_revision'), 'code' => 'stale_revision'], 409);
            }
        }

        return $next($request);
    }
}
