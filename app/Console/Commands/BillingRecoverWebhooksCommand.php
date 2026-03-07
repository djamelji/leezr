<?php

namespace App\Console\Commands;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingExpectedConfirmation;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\Stripe\StripeEventProcessor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-228: Webhook Recovery Pipeline.
 *
 * Polls Stripe for overdue expected confirmations, synthesises
 * webhook payloads, and feeds them through StripeEventProcessor.
 *
 * Designed for scheduling every 10 minutes. Idempotent + Isolatable.
 */
class BillingRecoverWebhooksCommand extends Command implements Isolatable
{
    protected $signature = 'billing:recover-webhooks {--dry-run}';

    protected $description = 'Recover missed Stripe webhook events by polling pending expected confirmations';

    public function handle(StripePaymentAdapter $adapter, StripeEventProcessor $processor): int
    {
        BillingJobHeartbeat::start('billing:recover-webhooks');

        $dryRun = $this->option('dry-run');

        $overdue = BillingExpectedConfirmation::where('status', 'pending')
            ->where('expected_by', '<=', now())
            ->oldest('expected_by')
            ->limit(50)
            ->get();

        $this->info("Found {$overdue->count()} overdue expected confirmation(s).");
        Log::channel('billing')->info('billing:recover-webhooks started', ['overdue' => $overdue->count()]);

        if ($overdue->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['recovered' => 0, 'expired' => 0, 'failed' => 0];

        foreach ($overdue as $ec) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] #{$ec->id} — {$ec->expected_event_type} ref={$ec->provider_reference}");

                continue;
            }

            try {
                $result = $this->recoverOne($ec, $adapter, $processor);
                $stats[$result]++;
                $this->line("  #{$ec->id} — {$result}");
            } catch (\Throwable $e) {
                $stats['failed']++;
                $ec->update(['status' => 'expired']);

                Log::channel('billing')->error('billing:recover-webhooks failed', [
                    'expected_confirmation_id' => $ec->id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  #{$ec->id} — error: {$e->getMessage()}");
            }
        }

        $this->info("Recovered: {$stats['recovered']}, Expired: {$stats['expired']}, Failed: {$stats['failed']}");
        Log::channel('billing')->info('billing:recover-webhooks finished', $stats);

        BillingJobHeartbeat::finish('billing:recover-webhooks', $stats['failed'] > 0 ? 'failed' : 'ok', $stats);

        return self::SUCCESS;
    }

    private function recoverOne(
        BillingExpectedConfirmation $ec,
        StripePaymentAdapter $adapter,
        StripeEventProcessor $processor,
    ): string {
        $object = $this->pollStripe($ec, $adapter);

        if (! $object) {
            // Object not found in Stripe — mark expired
            $ec->update(['status' => 'expired']);

            return 'expired';
        }

        $stripeStatus = $object['status'] ?? null;

        // Check if the Stripe object has actually completed
        if (! $this->isCompleted($ec->expected_event_type, $stripeStatus)) {
            // Still pending on Stripe side — mark expired (we can't recover yet)
            $ec->update(['status' => 'expired']);

            return 'expired';
        }

        // Synthesise a webhook payload and feed through processor
        $syntheticPayload = [
            'id' => 'evt_recovered_' . $ec->id . '_' . time(),
            'type' => $ec->expected_event_type,
            'created' => time(),
            'data' => ['object' => $object],
        ];

        $result = $processor->process($syntheticPayload);

        if ($result->handled) {
            $ec->update([
                'status' => 'recovered',
                'confirmed_at' => now(),
            ]);

            Log::channel('billing')->info('Webhook recovered via polling', [
                'expected_confirmation_id' => $ec->id,
                'event_type' => $ec->expected_event_type,
                'provider_reference' => $ec->provider_reference,
            ]);

            return 'recovered';
        }

        // Processor didn't handle it — mark expired
        $ec->update(['status' => 'expired']);

        return 'expired';
    }

    private function pollStripe(BillingExpectedConfirmation $ec, StripePaymentAdapter $adapter): ?array
    {
        $ref = $ec->provider_reference;

        if (! $ref) {
            return null;
        }

        try {
            $object = match ($ec->expected_event_type) {
                'checkout.session.completed' => $adapter->retrieveCheckoutSession($ref),
                'payment_intent.succeeded' => $adapter->retrievePaymentIntent($ref),
                'setup_intent.succeeded' => $adapter->retrieveSetupIntent($ref),
                default => null,
            };

            return $object ? $object->toArray() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isCompleted(string $eventType, ?string $status): bool
    {
        return match ($eventType) {
            'checkout.session.completed' => $status === 'complete',
            'payment_intent.succeeded' => $status === 'succeeded',
            'setup_intent.succeeded' => $status === 'succeeded',
            default => false,
        };
    }
}
