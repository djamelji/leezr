<?php

namespace App\Console\Commands;

use App\Console\Concerns\HasCorrelationId;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\CheckoutSessionActivator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-229: Checkout session recovery (leg 3 of triple recovery).
 *
 * Polls Stripe for checkout sessions that are still 'created' locally
 * but older than 10 minutes. If Stripe says completed → activate.
 * If expired/cancelled → update local status.
 *
 * Designed for scheduling every 10 minutes. Idempotent + Isolatable.
 */
class BillingRecoverCheckoutsCommand extends Command implements Isolatable
{
    use HasCorrelationId;

    protected $signature = 'billing:recover-checkouts {--dry-run}';

    protected $description = 'Recover missed checkout sessions by polling Stripe';

    public function handle(StripePaymentAdapter $adapter): int
    {
        $this->initCorrelationId();
        BillingJobHeartbeat::start('billing:recover-checkouts');

        $dryRun = $this->option('dry-run');

        $stale = BillingCheckoutSession::where('status', 'created')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->oldest('created_at')
            ->limit(100)
            ->get();

        $this->info("Found {$stale->count()} stale checkout session(s).");
        Log::channel('billing')->info('billing:recover-checkouts started', ['stale' => $stale->count()]);

        if ($stale->isEmpty()) {
            BillingJobHeartbeat::finish('billing:recover-checkouts', 'ok', []);

            return self::SUCCESS;
        }

        $stats = ['activated' => 0, 'expired' => 0, 'still_pending' => 0, 'failed' => 0];

        foreach ($stale as $session) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] #{$session->id} — session={$session->provider_session_id}");

                continue;
            }

            try {
                $result = $this->recoverOne($session, $adapter);
                $stats[$result]++;
                $this->line("  #{$session->id} — {$result}");
            } catch (\Throwable $e) {
                $stats['failed']++;

                Log::channel('billing')->error('billing:recover-checkouts failed', [
                    'checkout_session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  #{$session->id} — error: {$e->getMessage()}");
            }
        }

        $this->info("Activated: {$stats['activated']}, Expired: {$stats['expired']}, Still pending: {$stats['still_pending']}, Failed: {$stats['failed']}");
        Log::channel('billing')->info('billing:recover-checkouts finished', $stats);

        BillingJobHeartbeat::finish('billing:recover-checkouts', $stats['failed'] > 0 ? 'failed' : 'ok', $stats);

        return self::SUCCESS;
    }

    private function recoverOne(BillingCheckoutSession $session, StripePaymentAdapter $adapter): string
    {
        $session->update(['last_checked_at' => now()]);

        try {
            $stripeSession = $adapter->retrieveCheckoutSession($session->provider_session_id);
            $stripeData = $stripeSession->toArray();
        } catch (\Throwable) {
            return 'failed';
        }

        $stripeStatus = $stripeData['status'] ?? null;
        $paymentStatus = $stripeData['payment_status'] ?? null;

        // Completed + paid → activate via shared activator
        if ($stripeStatus === 'complete' && $paymentStatus === 'paid') {
            $result = CheckoutSessionActivator::activateFromStripeSession($stripeData);

            if ($result->activated) {
                Log::channel('billing')->info('Checkout recovered via cron', [
                    'checkout_session_id' => $session->id,
                    'provider_session_id' => $session->provider_session_id,
                    'company_id' => $session->company_id,
                ]);

                return 'activated';
            }

            return 'failed';
        }

        // Expired or cancelled
        if (in_array($stripeStatus, ['expired'])) {
            $session->update(['status' => 'expired']);

            return 'expired';
        }

        // Still open on Stripe side
        return 'still_pending';
    }
}
