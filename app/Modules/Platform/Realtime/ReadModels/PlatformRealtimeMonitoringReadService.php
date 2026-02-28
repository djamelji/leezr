<?php

namespace App\Modules\Platform\Realtime\ReadModels;

use App\Core\Realtime\ConnectionTracker;
use App\Core\Realtime\MetricsCollector;
use App\Core\Realtime\TopicRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-157: Read-only aggregation of realtime backbone monitoring data.
 *
 * Decouples governance controller from runtime statics.
 */
class PlatformRealtimeMonitoringReadService
{
    /**
     * System status, driver config, topic registry.
     */
    public function status(): array
    {
        return [
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
        ];
    }

    /**
     * Event counters and latency stats.
     */
    public function metrics(): array
    {
        return [
            'events' => MetricsCollector::getMetrics(),
            'latency' => [
                'publish' => MetricsCollector::getLatencyStats('publish'),
                'delivery' => MetricsCollector::getLatencyStats('delivery'),
            ],
        ];
    }

    /**
     * Active SSE connections.
     */
    public function connections(): array
    {
        return [
            'connections' => ConnectionTracker::activeConnections(),
            'by_company' => ConnectionTracker::connectionsByCompany(),
            'global_count' => ConnectionTracker::globalCount(),
        ];
    }
}
