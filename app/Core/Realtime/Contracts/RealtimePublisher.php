<?php

namespace App\Core\Realtime\Contracts;

use App\Core\Realtime\EventEnvelope;

/**
 * Contract for publishing realtime events.
 *
 * ADR-125: implementations publish events to connected SSE clients.
 * ADR-126: accepts EventEnvelope (replaces RealtimeEvent).
 *
 * The publisher MUST be called AFTER the DB transaction commits.
 */
interface RealtimePublisher
{
    /**
     * Publish an event envelope to all SSE clients
     * subscribed to the event's company channel.
     */
    public function publish(EventEnvelope $envelope): void;
}
