<?php

namespace App\Console\Commands;

use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\BillingWebhookDeadLetter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-266: Auto-escalation when webhook dead letter queue exceeds threshold.
 *
 * Checks the count of pending dead letter entries. If it exceeds the
 * configured threshold, logs a CRITICAL alert for monitoring pickup.
 *
 * Designed for hourly scheduling. Idempotent: safe to run multiple times.
 */
class BillingCheckDlqCommand extends Command implements Isolatable
{
    protected $signature = 'billing:check-dlq {--threshold=10}';

    protected $description = 'Check webhook dead letter queue and alert if threshold exceeded';

    public function handle(): int
    {
        BillingJobHeartbeat::start('billing:check-dlq');

        $threshold = (int) $this->option('threshold');

        $pendingCount = BillingWebhookDeadLetter::where('status', 'pending')->count();
        $oldestPending = BillingWebhookDeadLetter::where('status', 'pending')
            ->orderBy('failed_at')
            ->first();

        $this->info("Dead letter queue: {$pendingCount} pending entries (threshold: {$threshold}).");

        if ($pendingCount >= $threshold) {
            $oldestAge = $oldestPending
                ? now()->diffInHours($oldestPending->failed_at) . ' hours'
                : 'unknown';

            Log::channel('billing')->critical('[billing:dlq] Dead letter queue threshold exceeded', [
                'pending_count' => $pendingCount,
                'threshold' => $threshold,
                'oldest_age' => $oldestAge,
                'oldest_event_type' => $oldestPending?->event_type,
            ]);

            $this->error("ALERT: {$pendingCount} pending dead letter entries (threshold: {$threshold}). Oldest: {$oldestAge}.");

            BillingJobHeartbeat::finish('billing:check-dlq', 'alert', [
                'pending_count' => $pendingCount,
                'threshold' => $threshold,
            ]);

            return self::FAILURE;
        }

        BillingJobHeartbeat::finish('billing:check-dlq', 'ok', [
            'pending_count' => $pendingCount,
        ]);

        return self::SUCCESS;
    }
}
