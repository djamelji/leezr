<?php

namespace App\Modules\Platform\Automations\Http;

use App\Core\Automation\ScheduledTaskRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * External scheduler-liveness endpoint (GET /health/scheduler), for an INDEPENDENT
 * off-host uptime monitor — a fully stopped scheduler cannot raise its own in-app alert.
 *
 * Security: token-gated (config automations.monitor_token). When no token is configured
 * the endpoint is DISABLED (404) so it is never exposed unauthenticated. No sensitive
 * data is returned — only a status + heartbeat age.
 *
 * Deploy-safe: 'unknown' (no heartbeat yet, e.g. right after a migrate:fresh reset) is
 * reported healthy to avoid false positives; only a stale heartbeat ('dead') returns 503.
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
        $unhealthy = $health['status'] === 'dead';

        return response()->json([
            'status' => $health['status'],
            'healthy' => ! $unhealthy,
            'heartbeat_age_seconds' => $health['heartbeat_age_seconds'],
            'last_heartbeat_at' => $health['last_heartbeat_at'],
        ], $unhealthy ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }
}
