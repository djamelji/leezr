<?php

namespace App\Console\Commands;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Modules\CompanyModuleActivationReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-227: Billing health check — detects structural inconsistencies.
 *
 * Checks:
 *   1. Subscriptions without invoices for current period
 *   2. Orphan addon subscriptions (module not enabled for company)
 *   3. Invoices open too long (>30 days)
 *   4. Stripe drift (provider=stripe invoice without matching Payment)
 *
 * Exit codes: 0 = healthy, 1 = warnings detected.
 */
class BillingHealthCheckCommand extends Command
{
    protected $signature = 'billing:health-check';

    protected $description = 'Detect billing inconsistencies and structural issues';

    public function handle(): int
    {
        $warnings = [];

        $this->info('Running billing health checks...');

        $warnings = array_merge(
            $warnings,
            $this->checkSubscriptionsWithoutInvoices(),
            $this->checkOrphanAddonSubscriptions(),
            $this->checkStaleOpenInvoices(),
            $this->checkStripeDrift(),
        );

        if (count($warnings) === 0) {
            $this->info('All billing checks passed. System is healthy.');
            Log::channel('billing')->info('billing:health-check passed — no issues');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Entity', 'Description'],
            $warnings,
        );

        $this->warn(count($warnings) . ' warning(s) detected.');
        Log::channel('billing')->warning('billing:health-check detected issues', [
            'count' => count($warnings),
            'warnings' => $warnings,
        ]);

        return self::FAILURE;
    }

    private function checkSubscriptionsWithoutInvoices(): array
    {
        $warnings = [];

        $subscriptions = Subscription::whereIn('status', ['active', 'trialing'])
            ->where('is_current', 1)
            ->whereNotNull('current_period_start')
            ->get();

        foreach ($subscriptions as $sub) {
            $hasInvoice = Invoice::where('subscription_id', $sub->id)
                ->whereDate('period_start', $sub->current_period_start->toDateString())
                ->exists();

            if (! $hasInvoice) {
                // Free plans (starter) don't generate invoices — skip them
                $plan = \App\Core\Plans\Plan::where('key', $sub->plan_key)->first();
                $price = ($sub->interval === 'yearly')
                    ? ($plan->price_yearly ?? 0)
                    : ($plan->price_monthly ?? 0);

                if ($price > 0) {
                    $warnings[] = [
                        'subscription',
                        "subscription:{$sub->id}",
                        "Missing invoice for current period (company:{$sub->company_id}, plan:{$sub->plan_key})",
                    ];
                }
            }
        }

        return $warnings;
    }

    private function checkOrphanAddonSubscriptions(): array
    {
        $warnings = [];

        $activeAddons = CompanyAddonSubscription::active()->get();

        foreach ($activeAddons as $addon) {
            $isEnabled = CompanyModuleActivationReason::where('company_id', $addon->company_id)
                ->where('module_key', $addon->module_key)
                ->exists();

            if (! $isEnabled) {
                $warnings[] = [
                    'addon',
                    "company:{$addon->company_id}",
                    "Orphan addon subscription for {$addon->module_key}",
                ];
            }
        }

        return $warnings;
    }

    private function checkStaleOpenInvoices(): array
    {
        $warnings = [];

        $staleInvoices = Invoice::where('status', 'open')
            ->whereNotNull('finalized_at')
            ->where('issued_at', '<=', now()->subDays(30))
            ->get();

        foreach ($staleInvoices as $invoice) {
            $warnings[] = [
                'invoice',
                "invoice:{$invoice->id}",
                "Open invoice >30 days (company:{$invoice->company_id}, amount:{$invoice->amount_due})",
            ];
        }

        return $warnings;
    }

    private function checkStripeDrift(): array
    {
        $warnings = [];

        $stripeInvoices = Invoice::whereIn('status', ['paid'])
            ->whereNotNull('finalized_at')
            ->whereHas('subscription', fn ($q) => $q->where('provider', 'stripe'))
            ->where('amount_due', '>', 0)
            ->get();

        foreach ($stripeInvoices as $invoice) {
            $hasPayment = Payment::where('invoice_id', $invoice->id)
                ->where('provider', 'stripe')
                ->exists();

            if (! $hasPayment) {
                $warnings[] = [
                    'stripe_drift',
                    "invoice:{$invoice->id}",
                    "Paid Stripe invoice without matching Payment record (company:{$invoice->company_id})",
                ];
            }
        }

        return $warnings;
    }
}
