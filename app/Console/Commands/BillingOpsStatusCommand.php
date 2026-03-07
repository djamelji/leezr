<?php

namespace App\Console\Commands;

use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\BillingExpectedConfirmation;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use Illuminate\Console\Command;

/**
 * ADR-233: Billing ops status dashboard.
 *
 * Displays heartbeats and counters for operational health monitoring.
 * Exit code 0 = OK, 1 = anomalies detected.
 */
class BillingOpsStatusCommand extends Command
{
    protected $signature = 'billing:ops-status';

    protected $description = 'Display billing system operational status and detect anomalies';

    public function handle(): int
    {
        $anomalies = 0;

        // ── Heartbeats ──
        $this->info('=== Job Heartbeats ===');
        $heartbeats = BillingJobHeartbeat::all();

        if ($heartbeats->isEmpty()) {
            $this->warn('  No heartbeats recorded yet.');
        } else {
            foreach ($heartbeats as $hb) {
                $lastRun = $hb->last_finished_at?->diffForHumans() ?? 'never';
                $status = $hb->last_status ?? 'unknown';
                $statusColor = $status === 'ok' ? 'info' : 'error';

                $this->line("  {$hb->job_key}: last run {$lastRun} — status: {$status}");

                if ($hb->last_status === 'failed') {
                    $anomalies++;
                    $this->error("    ^ Last run failed: {$hb->last_error}");
                }
            }
        }

        // ── Counters ──
        $this->newLine();
        $this->info('=== Billing Counters ===');

        $pendingCheckouts = BillingCheckoutSession::where('status', 'created')
            ->where('created_at', '<=', now()->subHour())
            ->count();
        $this->line("  Pending checkouts (>1h old): {$pendingCheckouts}");
        if ($pendingCheckouts > 0) {
            $anomalies++;
            $this->warn("    ^ Stale checkout sessions detected.");
        }

        $overdueConfirmations = BillingExpectedConfirmation::where('status', 'pending')
            ->where('expected_by', '<=', now())
            ->count();
        $this->line("  Overdue expected confirmations: {$overdueConfirmations}");
        if ($overdueConfirmations > 0) {
            $anomalies++;
            $this->warn("    ^ Overdue confirmations need recovery.");
        }

        $pendingDeadLetters = BillingWebhookDeadLetter::where('status', 'pending')->count();
        $this->line("  Dead letters pending replay: {$pendingDeadLetters}");
        if ($pendingDeadLetters > 0) {
            $anomalies++;
            $this->warn("    ^ Dead letters need replay.");
        }

        $overdueInvoices = Invoice::where('status', 'overdue')->count();
        $this->line("  Overdue invoices: {$overdueInvoices}");

        $pastDueSubs = Subscription::where('status', 'past_due')->count();
        $this->line("  Past-due subscriptions: {$pastDueSubs}");

        $suspendedSubs = Subscription::where('status', 'suspended')->count();
        $this->line("  Suspended subscriptions: {$suspendedSubs}");

        // ── Verdict ──
        $this->newLine();
        if ($anomalies > 0) {
            $this->error("ANOMALIES DETECTED: {$anomalies} issue(s) found.");

            return self::FAILURE;
        }

        $this->info('ALL OK — No anomalies detected.');

        return self::SUCCESS;
    }
}
