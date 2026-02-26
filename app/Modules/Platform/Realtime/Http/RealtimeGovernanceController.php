<?php

namespace App\Modules\Platform\Realtime\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\ConnectionTracker;
use App\Core\Realtime\MetricsCollector;
use App\Core\Realtime\TopicRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-127: Platform governance endpoints for the realtime backbone.
 *
 * Requires manage_realtime platform permission.
 */
class RealtimeGovernanceController
{
    /**
     * GET /platform/api/realtime/status
     * System status, driver config, topic registry.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'driver' => config('realtime.driver'),
            'envelope_version' => 2,
            'heartbeat_interval' => config('realtime.heartbeat_interval'),
            'stream_timeout' => config('realtime.stream_timeout'),
            'max_streams_per_company' => config('realtime.max_streams_per_company'),
            'max_streams_global' => config('realtime.max_streams_global'),
            'topics' => TopicRegistry::keys(),
            'topic_count' => count(TopicRegistry::keys()),
            'transport' => config('realtime.transport', 'polling'),
            'kill_switch' => Cache::get('realtime:kill_switch', false),
        ]);
    }

    /**
     * GET /platform/api/realtime/metrics
     * Event counters and latency stats.
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'events' => MetricsCollector::getMetrics(),
            'latency' => [
                'publish' => MetricsCollector::getLatencyStats('publish'),
                'delivery' => MetricsCollector::getLatencyStats('delivery'),
            ],
        ]);
    }

    /**
     * GET /platform/api/realtime/connections
     * Active SSE connections.
     */
    public function connections(): JsonResponse
    {
        return response()->json([
            'connections' => ConnectionTracker::activeConnections(),
            'by_company' => ConnectionTracker::connectionsByCompany(),
            'global_count' => ConnectionTracker::globalCount(),
        ]);
    }

    /**
     * POST /platform/api/realtime/flush
     * Flush all realtime Redis keys (events, metrics, connections).
     */
    public function flush(Request $request): JsonResponse
    {
        MetricsCollector::reset();

        // ADR-130: audit log
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

        // ADR-130: audit log
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
