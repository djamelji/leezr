<?php

namespace App\Modules\Infrastructure\Realtime\Http;

use App\Core\Realtime\Adapters\SseRealtimePublisher;
use App\Core\Realtime\ConnectionTracker;
use App\Core\Realtime\Contracts\StreamTransport;
use App\Core\Realtime\EventCategory;
use App\Core\Realtime\MetricsCollector;
use App\Core\Realtime\TopicRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE stream endpoint — company-scoped realtime events.
 *
 * ADR-125: GET /api/realtime/stream
 * ADR-126: Multi-category envelope dispatch.
 * ADR-127: Subscription filtering via ?categories= and ?topics= params.
 * ADR-128: Transport abstraction — swap PollingTransport for PubSubTransport under Octane.
 *
 * The client opens an EventSource to this endpoint. The server holds
 * the connection open, uses the injected StreamTransport to read events,
 * and relays them as SSE messages with category-based event types.
 *
 * Protected by auth:sanctum + company.context middleware.
 */
class RealtimeStreamController
{
    public function __invoke(Request $request, StreamTransport $transport): StreamedResponse
    {
        $company = $request->attributes->get('company');
        $companyId = $company->id;
        $userId = $request->user()->id;

        $heartbeatInterval = config('realtime.heartbeat_interval', 30);
        $streamTimeout = config('realtime.stream_timeout', 300);
        $redisKey = SseRealtimePublisher::keyFor($companyId);

        // ADR-127: Parse subscription filters (empty = all events)
        $categoryFilter = $this->parseFilter($request->query('categories'));
        $topicFilter = $this->parseFilter($request->query('topics'));

        // ADR-127: Connection governance — reject if limits exceeded
        if (!ConnectionTracker::connect($userId, $companyId, $request->ip())) {
            Log::warning('[realtime] connection rejected — limit exceeded', [
                'user_id' => $userId,
                'company_id' => $companyId,
            ]);

            return response()->json(['message' => 'Too many connections.'], 429);
        }

        Log::debug('[realtime] connect', [
            'user_id' => $userId,
            'company_id' => $companyId,
            'categories' => $categoryFilter,
            'topics' => $topicFilter,
        ]);

        $response = new StreamedResponse(function () use ($transport, $redisKey, $heartbeatInterval, $streamTimeout, $companyId, $userId, $categoryFilter, $topicFilter) {
            // Disable output buffering for real-time streaming
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level()) {
                ob_end_clean();
            }

            $startedAt = time();
            $lastHeartbeat = time();
            $lastTimestamp = microtime(true);

            // Send initial connected event
            $this->sendEvent('connected', [
                'company_id' => $companyId,
                'topics' => TopicRegistry::keys(),
                'subscribed_categories' => $categoryFilter,
                'subscribed_topics' => $topicFilter,
            ]);

            while (!connection_aborted() && (time() - $startedAt) < $streamTimeout) {
                try {
                    // ADR-128: Use injected transport to read events
                    $events = $transport->poll($redisKey, $lastTimestamp);

                    foreach ($events as $event) {
                        $data = json_decode($event['json'], true);
                        if (!$data || !isset($data['topic'])) {
                            $lastTimestamp = $event['score'];

                            continue;
                        }

                        // Validate topic (defense in depth)
                        if (!TopicRegistry::exists($data['topic'])) {
                            $lastTimestamp = $event['score'];

                            continue;
                        }

                        // ADR-127: Apply subscription filters
                        if (!$this->matchesFilters($data, $categoryFilter, $topicFilter)) {
                            $lastTimestamp = $event['score'];

                            continue;
                        }

                        // Enrich with invalidation keys from TopicRegistry
                        $data['invalidates'] = TopicRegistry::invalidates($data['topic']);

                        // ADR-126: Use category-based SSE event type
                        $sseEventType = $this->resolveSseEventType($data);

                        $this->sendEvent($sseEventType, $data);

                        // ADR-128: Record delivery latency
                        $deliveryMs = (microtime(true) - $event['score']) * 1000;
                        MetricsCollector::recordDeliveryLatency($deliveryMs);

                        $lastTimestamp = $event['score'];
                    }
                } catch (\Throwable $e) {
                    // Transport error mid-stream → send error and close
                    Log::warning('[realtime] stream read failed', [
                        'company_id' => $companyId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->sendEvent('error', ['message' => 'Stream read error']);

                    break;
                }

                // Heartbeat
                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    $this->sendComment('heartbeat');
                    $lastHeartbeat = time();
                }

                // ADR-128: Use transport-controlled sleep interval
                $transport->sleep();
            }

            // ADR-127: Unregister connection
            ConnectionTracker::disconnect($userId, $companyId);

            Log::debug('[realtime] disconnect', [
                'user_id' => $userId,
                'company_id' => $companyId,
                'duration' => time() - $startedAt,
            ]);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * ADR-127: Parse a comma-separated filter string into an array.
     *
     * @return string[] Empty array means "no filter" (accept all).
     */
    private function parseFilter(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * ADR-127: Check if an event matches the subscription filters.
     *
     * Empty filter arrays mean "accept all" (backward compat).
     */
    private function matchesFilters(array $data, array $categoryFilter, array $topicFilter): bool
    {
        // Category filter
        if (!empty($categoryFilter)) {
            $eventCategory = $data['category'] ?? 'invalidation';
            if (!in_array($eventCategory, $categoryFilter, true)) {
                return false;
            }
        }

        // Topic filter
        if (!empty($topicFilter)) {
            if (!in_array($data['topic'], $topicFilter, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ADR-126: Resolve the SSE event type from envelope data.
     *
     * If the envelope has a category field, map it to the SSE event type
     * via EventCategory enum. Otherwise, default to 'invalidate' for
     * backward compat with ADR-125 v1 events.
     */
    private function resolveSseEventType(array $data): string
    {
        if (!isset($data['category'])) {
            return 'invalidate'; // v1 backward compat
        }

        $category = EventCategory::tryFrom($data['category']);

        return $category?->sseEventType() ?? 'invalidate';
    }

    /**
     * Send an SSE event to the client.
     */
    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Send an SSE comment (heartbeat / keepalive).
     */
    private function sendComment(string $text): void
    {
        echo ": {$text}\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
