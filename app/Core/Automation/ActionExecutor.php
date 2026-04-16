<?php

namespace App\Core\Automation;

use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-437: Executes workflow actions.
 *
 * Supported action types:
 * - send_notification: publishes a domain event for SSE notification
 * - webhook: HTTP POST to an external URL
 * - log: write to workflow log (debug/audit)
 *
 * All actions are fire-and-forget. Failures are logged but don't block.
 */
final class ActionExecutor
{
    /**
     * Execute a list of actions.
     *
     * @param  array  $actions  [{type, config}]
     * @param  array  $context  {company_id, trigger_topic, payload, rule_id}
     * @return array  Execution results [{type, status, error?}]
     */
    public static function execute(array $actions, array $context): array
    {
        $results = [];

        foreach ($actions as $action) {
            $type = $action['type'] ?? 'unknown';
            $config = $action['config'] ?? [];

            try {
                match ($type) {
                    'send_notification' => self::executeSendNotification($config, $context),
                    'webhook' => self::executeWebhook($config, $context),
                    'log' => self::executeLog($config, $context),
                    default => throw new \InvalidArgumentException("Unknown action type: {$type}"),
                };

                $results[] = ['type' => $type, 'status' => 'success'];
            } catch (\Throwable $e) {
                Log::warning('[workflow] action failed', [
                    'type' => $type,
                    'rule_id' => $context['rule_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                $results[] = ['type' => $type, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private static function executeSendNotification(array $config, array $context): void
    {
        $companyId = $context['company_id'] ?? null;
        if (! $companyId) {
            return;
        }

        try {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::domain('automation.updated', $companyId, [
                    'title' => $config['title'] ?? 'Workflow notification',
                    'body' => $config['body'] ?? '',
                    'severity' => $config['severity'] ?? 'info',
                    'trigger_topic' => $context['trigger_topic'] ?? '',
                    'rule_id' => $context['rule_id'] ?? null,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('[workflow] notification publish failed', ['error' => $e->getMessage()]);
        }
    }

    private static function executeWebhook(array $config, array $context): void
    {
        $url = $config['url'] ?? null;
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid webhook URL');
        }

        Http::timeout(10)->post($url, [
            'trigger_topic' => $context['trigger_topic'] ?? '',
            'payload' => $context['payload'] ?? [],
            'rule_id' => $context['rule_id'] ?? null,
            'company_id' => $context['company_id'] ?? null,
            'executed_at' => now()->toISOString(),
        ]);
    }

    private static function executeLog(array $config, array $context): void
    {
        Log::info('[workflow] action log', [
            'message' => $config['message'] ?? 'Workflow executed',
            'rule_id' => $context['rule_id'] ?? null,
            'trigger_topic' => $context['trigger_topic'] ?? '',
        ]);
    }
}
