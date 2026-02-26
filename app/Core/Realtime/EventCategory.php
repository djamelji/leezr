<?php

namespace App\Core\Realtime;

/**
 * ADR-126: Event categories for the realtime backbone.
 *
 * Each category maps to an SSE event type. Invalidation retains
 * backward compat with ADR-125 ('invalidate' event type).
 */
enum EventCategory: string
{
    case Invalidation = 'invalidation';
    case Domain = 'domain';
    case Notification = 'notification';
    case Audit = 'audit';
    case Security = 'security';

    /**
     * SSE event type name sent to the browser.
     *
     * Invalidation → 'invalidate' (ADR-125 backward compat).
     * All others → lowercase category name.
     */
    public function sseEventType(): string
    {
        return match ($this) {
            self::Invalidation => 'invalidate',
            default => $this->value,
        };
    }
}
