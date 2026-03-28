<?php

namespace App\Core\Realtime;

use App\Core\Realtime\Contracts\RealtimePublisher;
use Illuminate\Support\Facades\Log;

/**
 * ADR-427: Trait for easy SSE event emission.
 *
 * Usage in any service, controller, job, or use case:
 *   $this->publishDomainEvent('document.updated', $companyId, [...]);
 */
trait PublishesRealtimeEvents
{
    protected function publishDomainEvent(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): void
    {
        if ($companyId === null) {
            return;
        }

        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain($topic, $companyId, $payload, $userId)
            );
        } catch (\Throwable $e) {
            Log::warning('[realtime] publish failed (non-blocking)', [
                'topic' => $topic,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function publishNotificationEvent(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): void
    {
        if ($companyId === null) {
            return;
        }

        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::notification($topic, $companyId, $payload, $userId)
            );
        } catch (\Throwable $e) {
            Log::warning('[realtime] publish failed (non-blocking)', [
                'topic' => $topic,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
