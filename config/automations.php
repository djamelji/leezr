<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler health thresholds (seconds)
    |--------------------------------------------------------------------------
    | Drive ScheduledTaskRegistry::globalHealth() from the per-minute heartbeat.
    | ok: heartbeat age <= ok ; silent: between ; dead: age > dead.
    */
    'heartbeat_ok_seconds' => (int) env('SCHEDULER_HEARTBEAT_OK_SECONDS', 180),
    'heartbeat_dead_seconds' => (int) env('SCHEDULER_HEARTBEAT_DEAD_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Supervision thresholds (Alert Center)
    |--------------------------------------------------------------------------
    */
    'queue_backlog_threshold' => (int) env('AUTOMATIONS_QUEUE_BACKLOG_THRESHOLD', 200),
    'task_failure_threshold' => (int) env('AUTOMATIONS_TASK_FAILURE_THRESHOLD', 3),

    /*
    |--------------------------------------------------------------------------
    | External monitor token
    |--------------------------------------------------------------------------
    | Secret required to read the external scheduler-health endpoint
    | (GET /health/scheduler). When empty, the endpoint is DISABLED (404) so it is
    | never exposed unauthenticated. Set a long random value in the server .env and
    | configure your external uptime monitor to send it as ?token= or X-Monitor-Token.
    */
    'monitor_token' => env('SCHEDULER_MONITOR_TOKEN', ''),

    /*
    | Deploy grace: on 'unknown' (no heartbeat yet, e.g. right after a reset) the
    | endpoint returns healthy to avoid false positives during deployments. Only a
    | genuinely stale heartbeat ('dead') reports unhealthy (503).
    */
];
