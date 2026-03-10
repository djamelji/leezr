<?php

namespace App\Core\Billing\Dunning;

use App\Core\Billing\Invoice;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * State transition logic for the dunning engine.
 *
 * Handles marking invoices as overdue, applying failure actions
 * (suspend/cancel), and bounded reactivation after payment.
 */
class DunningTransitioner
{
    /**
     * Mark an open invoice as overdue and schedule first retry.
     * Also transitions the subscription to past_due.
     */
    public static function markOverdue(Invoice $invoice, PlatformBillingPolicy $policy): void
    {
        DB::transaction(function () use ($invoice, $policy) {
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            // Guard: only open invoices
            if ($invoice->status !== 'open') {
                return;
            }

            $retryIntervals = $policy->retry_intervals_days ?? [1, 3, 7];
            $nextRetryDays = $retryIntervals[0] ?? 1;

            $invoice->update([
                'status' => 'overdue',
                'next_retry_at' => now()->addDays($nextRetryDays),
            ]);

            // Transition subscription to past_due (skip trialing — no dunning during trial)
            if ($invoice->subscription_id) {
                Subscription::where('id', $invoice->subscription_id)
                    ->where('status', 'active')
                    ->update(['status' => 'past_due']);
            }
        });
    }

    /**
     * Apply the failure action to a company when max retries are exhausted.
     *
     * suspend: subscription → suspended, company → suspended
     * cancel: subscription → cancelled, scheduled intents → cancelled,
     *         company.plan_key → starter, company → suspended
     *
     * Idempotent: if company is already suspended, no-op.
     */
    public static function applyFailureAction(Company $company, PlatformBillingPolicy $policy): void
    {
        $action = $policy->failure_action;

        // Find the active/past_due subscription (skip trialing — no dunning during trial)
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if ($action === 'suspend') {
            // Subscription → suspended
            if ($subscription) {
                $subscription->update(['status' => 'suspended', 'is_current' => null]);
            }

            // Company → suspended (idempotent)
            if ($company->status !== 'suspended') {
                $company->update(['status' => 'suspended']);

                // ADR-226: Notify company owner of suspension
                DunningNotifier::notifyAccountSuspended($company);
            }
        } elseif ($action === 'cancel') {
            // Subscription → cancelled
            if ($subscription) {
                $subscription->update(['status' => 'cancelled', 'is_current' => null]);
            }

            // Cancel all scheduled PlanChangeIntents for this company
            PlanChangeIntent::where('company_id', $company->id)
                ->scheduled()
                ->update(['status' => 'cancelled']);

            // Downgrade company to free tier
            $wasSuspended = $company->status === 'suspended';

            $company->update([
                'plan_key' => 'starter',
                'status' => $company->status !== 'suspended' ? 'suspended' : $company->status,
            ]);

            // ADR-226: Notify company owner of suspension (only if newly suspended)
            if (! $wasSuspended) {
                DunningNotifier::notifyAccountSuspended($company);
            }
        }
    }

    /**
     * Bounded reactivation: when an overdue invoice is paid, check if
     * the subscription and company can be reactivated.
     *
     * Rules:
     *   - Subscription reverts to active if no more overdue invoices for it
     *   - Company reverts to active if suspended and no more overdue/uncollectible invoices
     *   - Uncollectible invoices block reactivation until paid
     */
    public static function checkReactivation(Company $company, ?int $subscriptionId): void
    {
        // Reactivate subscription if no remaining overdue invoices
        if ($subscriptionId) {
            $hasSubscriptionOverdue = Invoice::where('subscription_id', $subscriptionId)
                ->where('status', 'overdue')
                ->exists();

            if (!$hasSubscriptionOverdue) {
                Subscription::where('id', $subscriptionId)
                    ->where('status', 'past_due')
                    ->update(['status' => 'active']);
            }
        }

        // Reactivate company if suspended and no outstanding invoices
        $company->refresh();

        if ($company->status === 'suspended') {
            $hasCompanyOutstanding = Invoice::where('company_id', $company->id)
                ->whereIn('status', ['overdue', 'uncollectible'])
                ->exists();

            if (!$hasCompanyOutstanding) {
                $company->update(['status' => 'active']);
            }
        }
    }
}
