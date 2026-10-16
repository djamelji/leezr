<?php

namespace App\Modules\Infrastructure\System\Http;

use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * External scheduler-liveness probe (GET /health/scheduler), for an INDEPENDENT
 * off-host uptime monitor — a fully stopped scheduler cannot raise its own in-app alert.
 *
 * This is a system health endpoint (sibling of HealthController), intentionally public
 * and NOT module-gated — external monitors carry no session/module context. It lives in
 * Infrastructure\System so it is exempt from the module-activation invariant.
 *
 * Security: token-gated (config automations.monitor_token). When no token is configured
 * the endpoint is DISABLED (404) so it is never exposed unauthenticated. No sensitive
 * data is returned — only a status + heartbeat age.
 *
 * HTTP contract (for the external monitor):
 *   200 + status ok|silent      → healthy, scheduler firing.
 *   200 + status starting       → no heartbeat YET, but within the post-deploy grace
 *                                  window; NOT asserted healthy (healthy=false) — do
 *                                  not alert, it is transient.
 *   503 + status dead           → stale heartbeat OR no heartbeat after the grace
 *                                  elapsed (scheduler never started) → ALERT.
 *   404                         → endpoint disabled (no token) or bad token.
 * An 'unknown' state is thus never presented as proof of health.
 */
class SchedulerHealthEndpointController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('automations.monitor_token', '');

        if ($expected === '') {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $provided = (string) ($request->header('X-Monitor-Token') ?? $request->query('token', ''));

        // Constant-time comparison; generic 404 (do not reveal the endpoint exists).
        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $health = ScheduledTaskRegistry::globalHealth();
        $status = $health['status'];

        // 'unknown' (no heartbeat yet) is NEVER asserted healthy. It is tolerated only
        // within a short startup grace after install/deploy (status 'starting', HTTP 200);
        // once the grace elapses with still no heartbeat, the scheduler never started →
        // report unhealthy (HTTP 503). 'dead' (stale beat) is always unhealthy.
        if ($status === 'unknown') {
            $graceSeconds = (int) config('automations.heartbeat_grace_seconds', 600);
            // Lazily anchor the grace window (first probe ~ deploy time) if not set yet.
            $installedAt = SchedulerHeartbeat::singleton()->installed_at;
            $withinGrace = $installedAt !== null
                && abs($installedAt->diffInSeconds(now())) <= $graceSeconds;

            $status = $withinGrace ? 'starting' : 'dead';
        }

        $healthy = in_array($status, ['ok', 'silent'], true);
        $unhealthy = $status === 'dead';

        return response()->json([
            'status' => $status,            // ok | silent | starting | dead
            'healthy' => $healthy,          // true only for ok/silent — never for starting/unknown
            'heartbeat_age_seconds' => $health['heartbeat_age_seconds'],
            'last_heartbeat_at' => $health['last_heartbeat_at'],
        ], $unhealthy ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }
}
