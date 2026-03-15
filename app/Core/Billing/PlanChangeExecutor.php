<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\PlatformModule;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use App\Core\Billing\InvoiceLineDescriptor;
use App\Notifications\Billing\PlanChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Executes plan changes — transactional pipeline.
 *
 * Flow:
 *   1. Schedule: create PlanChangeIntent with proration snapshot
 *   2. Execute: apply the change inside a DB transaction
 *   3. Batch: executeScheduled() for deferred changes (end_of_period, end_of_trial)
 *
 * Invariants:
 *   - Only 1 scheduled intent per company at a time
 *   - Executed intents are immutable
 *   - Idempotency via idempotency_key
 *   - All amounts in cents
 */
class PlanChangeExecutor
{
    /**
     * Schedule a plan change intent.
     *
     * Timing determines when the change takes effect:
     *   - immediate: effective_at = now, executed right away
     *   - end_of_period: effective_at = subscription.current_period_end
     *   - end_of_trial: effective_at = subscription.trial_ends_at
     *
     * @return PlanChangeIntent The scheduled (or already executed) intent
     */
    public static function schedule(
        Company $company,
        string $toPlanKey,
        string $toInterval = 'monthly',
        ?string $timing = null,
        ?string $idempotencyKey = null,
    ): PlanChangeIntent {
        // Idempotency check
        if ($idempotencyKey !== null) {
            $existing = PlanChangeIntent::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        // Get current subscription
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            throw new RuntimeException('Company has no active subscription.');
        }

        // Cancel any existing scheduled intent for this company
        PlanChangeIntent::where('company_id', $company->id)
            ->scheduled()
            ->update(['status' => 'cancelled']);

        // Determine timing from policy if not explicit
        $fromLevel = PlanRegistry::level($subscription->plan_key);
        $toLevel = PlanRegistry::level($toPlanKey);

        if ($timing === null) {
            $policy = PlatformBillingPolicy::instance();
            $timing = $toLevel > $fromLevel
                ? $policy->upgrade_timing
                : $policy->downgrade_timing;
        }

        // Determine effective_at
        $effectiveAt = match ($timing) {
            'immediate' => CarbonImmutable::now(),
            'end_of_period' => $subscription->current_period_end
                ? CarbonImmutable::instance($subscription->current_period_end)
                : throw new RuntimeException('Cannot use end_of_period timing: subscription has no current_period_end.'),
            'end_of_trial' => $subscription->trial_ends_at
                ? CarbonImmutable::instance($subscription->trial_ends_at)
                : throw new RuntimeException('Cannot use end_of_trial timing: subscription has no trial_ends_at.'),
            default => throw new RuntimeException("Unknown timing: {$timing}"),
        };

        // Compute proration snapshot
        $prorationSnapshot = null;

        // ADR-287: Skip proration during trial with continue_trial policy
        $skipProration = $subscription->status === 'trialing'
            && PlatformBillingPolicy::instance()->trial_plan_change_behavior === 'continue_trial';

        // ADR-324: Use PricingEngine for effective prices (coupon-aware proration)
        if ($timing === 'immediate' && !$skipProration && $subscription->current_period_start && $subscription->current_period_end) {
            $oldPrice = PricingEngine::effectivePriceCents($subscription);
            $newPrice = PricingEngine::catalogPriceCents(
                \App\Core\Plans\Plan::where('key', $toPlanKey)->first(), $toInterval
            );

            $prorationSnapshot = ProrationCalculator::compute(
                oldPriceCents: $oldPrice,
                newPriceCents: $newPrice,
                periodStart: CarbonImmutable::instance($subscription->current_period_start),
                periodEnd: CarbonImmutable::instance($subscription->current_period_end),
                changeDate: CarbonImmutable::now(),
            );
        }

        $intent = PlanChangeIntent::create([
            'company_id' => $company->id,
            'from_plan_key' => $subscription->plan_key,
            'to_plan_key' => $toPlanKey,
            'interval_from' => $subscription->interval ?? 'monthly',
            'interval_to' => $toInterval,
            'timing' => $timing,
            'effective_at' => $effectiveAt,
            'proration_snapshot' => $prorationSnapshot,
            'status' => 'scheduled',
            'idempotency_key' => $idempotencyKey,
        ]);

        // If immediate, execute right away
        if ($timing === 'immediate') {
            $intent = static::execute($intent);

            // ADR-333: Auto-charge proration invoice (OUTSIDE transaction)
            static::autoChargeLatestInvoice($company);

            return $intent;
        }

        return $intent;
    }

    /**
     * Execute a scheduled plan change intent.
     *
     * Transactional pipeline:
     *   1. Lock subscription
     *   2. Update plan_key + interval
     *   3. If proration has net > 0, create invoice
     *   4. If proration has net < 0, credit wallet
     *   5. Mark intent as executed
     */
    public static function execute(PlanChangeIntent $intent): PlanChangeIntent
    {
        if (!$intent->isScheduled()) {
            throw new RuntimeException("Cannot execute intent #{$intent->id}: status is {$intent->status}.");
        }

        return DB::transaction(function () use ($intent) {
            // Re-fetch with lock
            $intent = PlanChangeIntent::where('id', $intent->id)->lockForUpdate()->first();

            if (!$intent->isScheduled()) {
                throw new RuntimeException("Cannot execute intent #{$intent->id}: status is {$intent->status}.");
            }

            // Lock subscription
            $subscription = Subscription::where('company_id', $intent->company_id)
                ->whereIn('status', ['active', 'trialing'])
                ->lockForUpdate()
                ->latest()
                ->first();

            if (!$subscription) {
                throw new RuntimeException("No active subscription for company #{$intent->company_id}.");
            }

            $company = $intent->company;
            $proration = $intent->proration_snapshot;

            // ADR-287: Determine trial plan change behavior
            $isTrialing = $subscription->status === 'trialing';
            $trialBehavior = $isTrialing
                ? PlatformBillingPolicy::instance()->trial_plan_change_behavior
                : null;

            // Resolve locale-aware invoice line descriptions
            $locale = $company->market?->locale ?? 'fr-FR';
            $desc = InvoiceLineDescriptor::resolve($locale);

            // Handle proration financial effects
            // ADR-287: Skip proration during trial with continue_trial policy
            if ($proration && $proration['net'] !== 0 && !($isTrialing && $trialBehavior === 'continue_trial')) {
                if ($proration['net'] > 0) {
                    // Company owes money → create proration invoice
                    $invoice = InvoiceIssuer::createDraft(
                        company: $company,
                        subscriptionId: $subscription->id,
                        periodStart: $subscription->current_period_start?->toDateString(),
                        periodEnd: $subscription->current_period_end?->toDateString(),
                    );

                    if ($proration['credit'] > 0) {
                        InvoiceIssuer::addLine(
                            invoice: $invoice,
                            type: 'proration',
                            description: $desc->prorationCredit(Plan::where('key', $intent->from_plan_key)->value('name') ?? $intent->from_plan_key, now(), $subscription->current_period_end, $proration['days_remaining']),
                            unitAmount: -$proration['credit'],
                            quantity: 1,
                        );
                    }

                    if ($proration['charge'] > 0) {
                        InvoiceIssuer::addLine(
                            invoice: $invoice,
                            type: 'proration',
                            description: $desc->prorationCharge(Plan::where('key', $intent->to_plan_key)->value('name') ?? $intent->to_plan_key, now(), $subscription->current_period_end, $proration['days_remaining']),
                            unitAmount: $proration['charge'],
                            quantity: 1,
                        );
                    }

                    // ADR-324: Apply coupon to proration invoice if active
                    if ($subscription->coupon_id) {
                        $coupon = BillingCoupon::find($subscription->coupon_id);
                        if ($coupon && $coupon->isUsable()) {
                            InvoiceIssuer::applyCoupon($invoice, $coupon, $company);
                        }
                    }

                    InvoiceIssuer::finalize($invoice);
                } else {
                    // Company is owed money → credit wallet
                    // ADR-337: Credit must be TTC (include tax) — the company originally
                    // paid TTC, so the refund must also be TTC. Otherwise a round-trip
                    // downgrade+upgrade costs the company the tax difference.
                    $creditHt = abs($proration['net']);
                    $taxRateBps = TaxResolver::resolveRateBps($company);
                    $creditTax = TaxResolver::compute($creditHt, $taxRateBps);
                    $policy = PlatformBillingPolicy::instance();
                    $creditAmount = $policy->tax_mode === 'inclusive'
                        ? $creditHt  // inclusive: subtotal already contains tax
                        : $creditHt + $creditTax;

                    // ADR-335: Rich description with plan names, dates and days
                    $fromName = Plan::where('key', $intent->from_plan_key)->value('name') ?? $intent->from_plan_key;
                    $toName = Plan::where('key', $intent->to_plan_key)->value('name') ?? $intent->to_plan_key;
                    $walletDesc = $desc->walletProrationCredit(
                        $fromName,
                        $toName,
                        now(),
                        $subscription->current_period_end,
                        $proration['days_remaining'] ?? null,
                    );

                    WalletLedger::credit(
                        company: $company,
                        amount: $creditAmount,
                        sourceType: 'plan_change_proration',
                        sourceId: $intent->id,
                        description: $walletDesc,
                        actorType: 'system',
                        idempotencyKey: "plan-change-credit-{$intent->id}",
                    );
                }
            }

            // Update subscription — ADR-324: transfer coupon
            $updateData = [
                'plan_key' => $intent->to_plan_key,
                'interval' => $intent->interval_to,
            ];

            if ($subscription->coupon_id) {
                $updateData['coupon_id'] = $subscription->coupon_id;
                $updateData['coupon_months_remaining'] = $subscription->coupon_months_remaining;
            }

            // ADR-287: Handle trialing subscription with immediate timing
            if ($isTrialing && $intent->timing === 'immediate') {
                if ($trialBehavior === 'end_trial') {
                    // End trial: activate subscription, start new billing period
                    $updateData['status'] = 'active';
                    $updateData['trial_ends_at'] = null;
                    $updateData['current_period_start'] = now();
                    $updateData['current_period_end'] = $intent->interval_to === 'yearly'
                        ? now()->addYear()
                        : now()->addMonth();
                }
                // continue_trial: only plan_key/interval change, status & trial_ends_at preserved
            } elseif ($intent->timing !== 'immediate') {
                // For end_of_period/end_of_trial deferred execution, reset the period
                $updateData['current_period_start'] = now();
                $updateData['current_period_end'] = $intent->interval_to === 'yearly'
                    ? now()->addYear()
                    : now()->addMonth();

                // If subscription was trialing and trial ended, switch to active
                if ($subscription->status === 'trialing' && $intent->timing === 'end_of_trial') {
                    $updateData['status'] = 'active';
                    $updateData['trial_ends_at'] = null;
                }
            }

            $subscription->update($updateData);

            // Update company plan_key
            $company->update(['plan_key' => $intent->to_plan_key]);

            // Sync addon intervals when interval or plan changes
            static::syncAddonSubscriptions($company, $intent->to_plan_key, $intent->interval_to);

            // Mark intent as executed
            $intent->update([
                'status' => 'executed',
                'executed_at' => now(),
            ]);

            // ADR-272: Notify company owner about plan change
            try {
                $owner = $company->owner();

                if ($owner) {
                    $plans = PlanRegistry::definitions();
                    $oldName = $plans[$intent->from_plan_key]->name ?? $intent->from_plan_key;
                    $newName = $plans[$intent->to_plan_key]->name ?? $intent->to_plan_key;

                    NotificationDispatcher::send(
                        topicKey: 'billing.plan_changed',
                        recipients: [$owner],
                        payload: ['from_plan' => $oldName, 'to_plan' => $newName],
                        company: $company,
                        mailNotification: new PlanChanged($oldName, $newName),
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[plan-change] Failed to send plan change notification', [
                    'company_id' => $company->id,
                    'intent_id' => $intent->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $intent->fresh();
        });
    }

    /**
     * Batch-execute all due scheduled intents.
     * Called by scheduler (e.g., daily cron).
     *
     * @return int Number of intents executed
     */
    public static function executeScheduled(): int
    {
        $dueIntents = PlanChangeIntent::due()->get();
        $executed = 0;

        foreach ($dueIntents as $intent) {
            try {
                static::execute($intent);
                $executed++;

                // ADR-333: Auto-charge proration invoice (OUTSIDE transaction)
                static::autoChargeLatestInvoice($intent->company);
            } catch (RuntimeException) {
                // Log but don't halt batch — other intents should still execute
                continue;
            }
        }

        return $executed;
    }

    /**
     * Sync active addon subscriptions when plan/interval changes.
     *
     * Recalculates addon amount_cents based on new plan and interval.
     * Yearly addons = monthly price × 12.
     */
    private static function syncAddonSubscriptions(Company $company, string $planKey, string $interval): void
    {
        $activeAddons = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->get();

        if ($activeAddons->isEmpty()) {
            return;
        }

        foreach ($activeAddons as $addon) {
            $module = PlatformModule::where('key', $addon->module_key)->first();

            if (! $module) {
                continue;
            }

            $monthlyAmount = ModuleQuoteCalculator::computeAmount($module, $planKey);
            $periodAmount = $interval === 'yearly' ? $monthlyAmount * 12 : $monthlyAmount;

            $addon->update([
                'interval' => $interval,
                'amount_cents' => $periodAmount,
            ]);

            Log::channel('billing')->info('Addon synced on plan change', [
                'company_id' => $company->id,
                'module_key' => $addon->module_key,
                'interval' => $interval,
                'amount_cents' => $periodAmount,
            ]);
        }
    }

    /**
     * ADR-333: Auto-charge the most recent open invoice for a company.
     * Must be called OUTSIDE any DB transaction.
     */
    private static function autoChargeLatestInvoice(Company $company): void
    {
        $invoice = Invoice::where('company_id', $company->id)
            ->where('status', 'open')
            ->where('amount_due', '>', 0)
            ->whereNotNull('finalized_at')
            ->latest('id')
            ->first();

        if (! $invoice) {
            return;
        }

        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        InvoiceAutoChargeService::attempt($invoice, $subscription);
    }
}
