<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * ADR-311: Prometheus-format billing metrics export.
 *
 * Authenticated via bearer token (config billing.metrics.token).
 * Returns text/plain with Prometheus exposition format.
 */
class BillingMetricsExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = config('billing.metrics.token');

        if (! $token || $request->bearerToken() !== $token) {
            abort(403, 'Invalid metrics token');
        }

        $lines = [];

        // Subscriptions by status
        $subCounts = Subscription::where('is_current', true)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        foreach (['active', 'trialing', 'past_due', 'suspended', 'cancelled'] as $status) {
            $lines[] = sprintf('billing_subscriptions_total{status="%s"} %d', $status, $subCounts[$status] ?? 0);
        }

        // Invoices by status
        $invCounts = Invoice::selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        foreach (['open', 'overdue', 'paid', 'voided', 'draft'] as $status) {
            $lines[] = sprintf('billing_invoices_total{status="%s"} %d', $status, $invCounts[$status] ?? 0);
        }

        // Revenue this month (from ledger revenue entries)
        $monthStart = now()->startOfMonth();
        $revenueMonth = LedgerEntry::where('account_code', 'revenue')
            ->where('recorded_at', '>=', $monthStart)
            ->sum('credit_cents');

        $lines[] = sprintf('billing_revenue_month_cents %d', $revenueMonth);

        // Total wallet balance
        $walletTotal = CompanyWallet::sum('balance');
        $lines[] = sprintf('billing_wallet_balance_total_cents %d', $walletTotal);

        // Dead letters pending
        $dlqPending = BillingWebhookDeadLetter::where('status', 'pending')->count();
        $lines[] = sprintf('billing_dead_letters_pending %d', $dlqPending);

        // Heartbeats
        $heartbeats = BillingJobHeartbeat::all();

        foreach ($heartbeats as $hb) {
            $timestamp = $hb->last_finished_at ? $hb->last_finished_at->timestamp : 0;
            $lines[] = sprintf('billing_heartbeat_last_run{job="%s"} %d', $hb->job_key, $timestamp);
        }

        return new Response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
