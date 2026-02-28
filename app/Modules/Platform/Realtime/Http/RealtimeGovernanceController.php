<?php

namespace App\Modules\Platform\Realtime\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\MetricsCollector;
use App\Modules\Platform\Realtime\ReadModels\PlatformRealtimeMonitoringReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-127, ADR-157: Platform governance endpoints for the realtime backbone.
 *
 * Read operations delegated to PlatformRealtimeMonitoringReadService.
 */
class RealtimeGovernanceController
{
    public function __construct(
        private readonly PlatformRealtimeMonitoringReadService $readService,
    ) {}

    /**
     * GET /platform/api/realtime/status
     */
    public function status(): JsonResponse
    {
        return response()->json($this->readService->status());
    }

    /**
     * GET /platform/api/realtime/metrics
     */
    public function metrics(): JsonResponse
    {
        return response()->json($this->readService->metrics());
    }

    /**
     * GET /platform/api/realtime/connections
     */
    public function connections(): JsonResponse
    {
        return response()->json($this->readService->connections());
    }

    /**
     * POST /platform/api/realtime/flush
     * Flush all realtime Redis keys (events, metrics, connections).
     */
    public function flush(Request $request): JsonResponse
    {
        MetricsCollector::reset();

        app(AuditLogger::class)->logPlatform(AuditAction::CHANNELS_FLUSHED, 'realtime', 'channels');

        return response()->json([
            'message' => 'Realtime data flushed.',
        ]);
    }

    /**
     * POST /platform/api/realtime/kill-switch
     * Toggle the kill switch (disables SSE driver at runtime).
     */
    public function killSwitch(Request $request): JsonResponse
    {
        $active = $request->boolean('active', true);

        Cache::put('realtime:kill_switch', $active, now()->addDay());

        app(AuditLogger::class)->logPlatform(
            $active ? AuditAction::KILL_SWITCH_ACTIVATED : AuditAction::KILL_SWITCH_DEACTIVATED,
            'realtime',
            'kill_switch',
        );

        return response()->json([
            'kill_switch' => $active,
            'message' => $active
                ? 'Kill switch activated — SSE connections will be refused.'
                : 'Kill switch deactivated — SSE connections are allowed.',
        ]);
    }
}
