<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime Driver
    |--------------------------------------------------------------------------
    |
    | ADR-125: SSE Invalidation Engine.
    |
    | Supported: "sse", "null"
    | - "sse"  → SseRealtimePublisher (Redis PubSub + SSE stream)
    | - "null" → NullRealtimePublisher (no-op, polling fallback)
    |
    */

    'driver' => env('REALTIME_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | ADR-128: SSE stream transport strategy.
    |
    | Supported: "polling", "pubsub"
    | - "polling" → PollingTransport (usleep 1s — low CPU, ~1s latency)
    | - "pubsub"  → PubSubTransport (usleep 100ms — higher CPU, ~100ms latency)
    |
    */

    'transport' => env('REALTIME_TRANSPORT', 'polling'),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Channel prefix for Redis PubSub. Each company gets its own channel:
    | {prefix}:company:{companyId}
    |
    */

    'redis_prefix' => env('REALTIME_REDIS_PREFIX', 'leezr:realtime'),

    'redis_connection' => env('REALTIME_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | SSE Stream Configuration
    |--------------------------------------------------------------------------
    |
    | heartbeat_interval: seconds between keepalive pings (prevents proxy timeout)
    | stream_timeout: max seconds a single SSE connection stays open before client reconnects
    |
    */

    'heartbeat_interval' => (int) env('REALTIME_HEARTBEAT_INTERVAL', 30),

    'stream_timeout' => (int) env('REALTIME_STREAM_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | ADR-127: Connection Governance
    |--------------------------------------------------------------------------
    |
    | max_streams_per_company: max concurrent SSE connections per company
    | max_streams_global: max concurrent SSE connections across all companies
    | connect_throttle_per_minute: max new connections per user per minute
    |
    */

    'max_streams_per_company' => (int) env('REALTIME_MAX_STREAMS_PER_COMPANY', 100),

    'max_streams_global' => (int) env('REALTIME_MAX_STREAMS_GLOBAL', 500),

    'connect_throttle_per_minute' => (int) env('REALTIME_CONNECT_THROTTLE', 5),

    /*
    |--------------------------------------------------------------------------
    | ADR-131: Event Flood Detection
    |--------------------------------------------------------------------------
    |
    | Auto kill switch: if more than `event_flood_threshold` events are
    | published within `event_flood_window` seconds, the kill switch is
    | automatically activated and a critical security alert is raised.
    |
    */

    'event_flood_threshold' => (int) env('REALTIME_EVENT_FLOOD_THRESHOLD', 1000),

    'event_flood_window' => (int) env('REALTIME_EVENT_FLOOD_WINDOW', 300),

];
