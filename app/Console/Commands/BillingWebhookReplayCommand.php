<?php

namespace App\Console\Commands;

use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Billing\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-228: Replay dead-lettered webhook events through StripeEventProcessor.
 *
 * Usage:
 *   billing:webhook-replay             → replay all pending dead letters
 *   billing:webhook-replay --id=42     → replay specific dead letter by ID
 *   billing:webhook-replay --dry-run   → preview what would be replayed
 */
class BillingWebhookReplayCommand extends Command implements Isolatable
{
    protected $signature = 'billing:webhook-replay {--id= : Replay a specific dead letter by ID} {--dry-run}';

    protected $description = 'Replay dead-lettered webhook events through the event processor';

    private const MAX_REPLAY_ATTEMPTS = 3;

    public function handle(StripeEventProcessor $processor): int
    {
        $dryRun = $this->option('dry-run');
        $specificId = $this->option('id');

        $query = BillingWebhookDeadLetter::where('status', 'pending')
            ->where('replay_attempts', '<', self::MAX_REPLAY_ATTEMPTS)
            ->oldest('failed_at');

        if ($specificId) {
            $query->where('id', $specificId);
        }

        $deadLetters = $query->limit(50)->get();

        $this->info("Found {$deadLetters->count()} dead letter(s) to replay.");
        Log::channel('billing')->info('billing:webhook-replay started', ['count' => $deadLetters->count()]);

        if ($deadLetters->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['replayed' => 0, 'failed' => 0];

        foreach ($deadLetters as $dl) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] #{$dl->id} — {$dl->event_type} event_id={$dl->event_id}");

                continue;
            }

            try {
                $this->replayOne($dl, $processor);
                $stats['replayed']++;
                $this->line("  #{$dl->id} — replayed successfully");
            } catch (\Throwable $e) {
                $stats['failed']++;
                $dl->increment('replay_attempts');
                $dl->update(['error_message' => $e->getMessage()]);

                Log::channel('billing')->error('billing:webhook-replay failed', [
                    'dead_letter_id' => $dl->id,
                    'event_id' => $dl->event_id,
                    'attempt' => $dl->replay_attempts,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  #{$dl->id} — error: {$e->getMessage()}");
            }
        }

        $this->info("Replayed: {$stats['replayed']}, Failed: {$stats['failed']}");
        Log::channel('billing')->info('billing:webhook-replay finished', $stats);

        return self::SUCCESS;
    }

    private function replayOne(BillingWebhookDeadLetter $dl, StripeEventProcessor $processor): void
    {
        $dl->increment('replay_attempts');

        // Remove the original failed webhook_event so it can be re-inserted
        WebhookEvent::where('provider_key', $dl->provider_key)
            ->where('event_id', $dl->event_id)
            ->where('status', 'failed')
            ->delete();

        // Re-insert as received
        $webhookEvent = WebhookEvent::create([
            'provider_key' => $dl->provider_key,
            'event_id' => $dl->event_id,
            'event_type' => $dl->event_type,
            'payload' => $dl->payload,
            'status' => 'received',
        ]);

        // Process through event processor
        DB::transaction(function () use ($webhookEvent, $dl, $processor) {
            $webhookEvent->update(['status' => 'processing']);

            $result = $processor->process($dl->payload);

            $webhookEvent->update([
                'status' => $result->handled ? 'processed' : 'ignored',
                'processed_at' => $result->handled ? now() : null,
                'error_message' => $result->error,
            ]);
        });

        // Mark dead letter as replayed
        $dl->update([
            'status' => 'replayed',
            'replayed_at' => now(),
        ]);
    }
}
