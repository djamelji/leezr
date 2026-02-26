<?php

namespace App\Core\Realtime\Adapters;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;

/**
 * No-op publisher. Used when REALTIME_DRIVER=null or in tests.
 * Events are silently discarded.
 */
class NullRealtimePublisher implements RealtimePublisher
{
    public function publish(EventEnvelope $envelope): void
    {
        // Intentionally empty — no-op fallback.
    }
}
