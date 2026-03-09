<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\ProrationCalculator;
use App\Core\Billing\Subscription;
use App\Core\Billing\TaxResolver;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries for company billing.
 * All methods are company-scoped — no cross-company data leakage.
 */
class CompanyBillingReadService
{
    /**
     * Billing overview: subscription + plan + addons + wallet + trial + payment method.
     */
    public static function overview(Company $company): array
    {
        $subscription = static::currentSubscription($company);
        $walletBalance = WalletLedger::balance($company);
        $currency = WalletLedger::ensureWallet($company)->currency;

        $outstandingCount = Invoice::where('company_id', $company->id)
            ->whereIn('status', ['open', 'overdue'])
            ->count();

        $outstandingAmount = (int) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['open', 'overdue'])
            ->sum('amount_due');

        // Plan details
        $plan = null;
        if ($subscription) {
            $planModel = Plan::where('key', $subscription['plan_key'])->first();
            if ($planModel) {
                $plan = [
                    'key' => $planModel->key,
                    'name' => $planModel->name,
                    'price_monthly' => $planModel->price_monthly,
                    'price_yearly' => $planModel->price_yearly,
                ];
            }
        }

        // Active addons (with module names)
        $moduleKeys = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->pluck('module_key')
            ->toArray();

        $moduleNames = ! empty($moduleKeys)
            ? PlatformModule::whereIn('key', $moduleKeys)->pluck('name', 'key')->toArray()
            : [];

        $addons = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->get()
            ->map(fn ($a) => [
                'module_key' => $a->module_key,
                'name' => $moduleNames[$a->module_key] ?? $a->module_key,
                'amount_cents' => $a->amount_cents,
                'interval' => $a->interval,
            ])
            ->toArray();

        // Trial info
        $trial = null;
        if ($subscription && $subscription['status'] === 'trialing' && $subscription['trial_ends_at']) {
            $trialEnd = \Carbon\Carbon::parse($subscription['trial_ends_at']);
            $daysRemaining = max(0, (int) now()->diffInDays($trialEnd, false));
            $planModel ??= Plan::where('key', $subscription['plan_key'])->first();
            $trial = [
                'ends_at' => $subscription['trial_ends_at'],
                'days_remaining' => $daysRemaining,
                'total_days' => $planModel?->trial_days ?? 14,
            ];
        }

        // Default payment method
        $defaultCard = CompanyPaymentProfile::where('company_id', $company->id)
            ->where('is_default', true)
            ->first();

        $paymentMethod = $defaultCard ? [
            'brand' => $defaultCard->metadata['brand'] ?? 'unknown',
            'last4' => $defaultCard->metadata['last4'] ?? '****',
            'exp_month' => $defaultCard->metadata['exp_month'] ?? null,
            'exp_year' => $defaultCard->metadata['exp_year'] ?? null,
        ] : null;

        return [
            'subscription' => $subscription,
            'plan' => $plan,
            'addons' => $addons,
            'trial' => $trial,
            'wallet_balance' => $walletBalance,
            'outstanding_invoices' => $outstandingCount,
            'outstanding_amount' => $outstandingAmount,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
        ];
    }

    /**
     * Paginated invoice list with optional filters.
     *
     * Filters: status, from (date), to (date)
     */
    public static function invoices(Company $company, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::where('company_id', $company->id)
            ->whereNotNull('finalized_at')
            ->orderByDesc('issued_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['from'])) {
            $query->where('issued_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('issued_at', '<=', $filters['to']);
        }

        return $query->paginate(min($perPage, 50));
    }

    /**
     * Single invoice detail with lines and credit notes.
     * Returns null if not found or not owned by company.
     */
    public static function invoiceDetail(Company $company, int $invoiceId): ?array
    {
        $invoice = Invoice::with(['lines', 'creditNotes'])
            ->where('company_id', $company->id)
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'amount' => $invoice->amount,
            'subtotal' => $invoice->subtotal,
            'tax_amount' => $invoice->tax_amount,
            'tax_rate_bps' => $invoice->tax_rate_bps,
            'wallet_credit_applied' => $invoice->wallet_credit_applied,
            'amount_due' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'issued_at' => $invoice->issued_at?->toISOString(),
            'due_at' => $invoice->due_at?->toISOString(),
            'paid_at' => $invoice->paid_at?->toISOString(),
            'finalized_at' => $invoice->finalized_at?->toISOString(),
            'voided_at' => $invoice->voided_at?->toISOString(),
            'retry_count' => $invoice->retry_count,
            'next_retry_at' => $invoice->next_retry_at?->toISOString(),
            'notes' => $invoice->notes,
            'provider' => $invoice->provider,
            'billing_snapshot' => $invoice->billing_snapshot,
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'amount' => $line->amount,
            ])->toArray(),
            'credit_notes' => $invoice->creditNotes->map(fn ($cn) => [
                'id' => $cn->id,
                'number' => $cn->number,
                'amount' => $cn->amount,
                'status' => $cn->status,
                'reason' => $cn->reason,
                'issued_at' => $cn->issued_at?->toISOString(),
                'applied_at' => $cn->applied_at?->toISOString(),
            ])->toArray(),
            'payments' => Payment::where('invoice_id', $invoice->id)
                ->where('company_id', $company->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'provider' => $p->provider,
                    'provider_payment_id' => $p->provider_payment_id,
                    'created_at' => $p->created_at->toISOString(),
                ])->toArray(),
            'ledger_entries' => LedgerEntry::where('company_id', $company->id)
                ->where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'entry_type' => $e->entry_type,
                    'account_code' => $e->account_code,
                    'debit' => $e->debit,
                    'credit' => $e->credit,
                    'currency' => $e->currency,
                    'correlation_id' => $e->correlation_id,
                    'recorded_at' => $e->recorded_at->toISOString(),
                ])->toArray(),
        ];
    }

    /**
     * Pending or rejected subscription awaiting admin decision (ADR-289).
     */
    public static function pendingSubscription(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['pending', 'rejected'])
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'interval' => $subscription->interval,
            'status' => $subscription->status,
            'created_at' => $subscription->created_at->toISOString(),
            'updated_at' => $subscription->updated_at->toISOString(),
        ];
    }

    /**
     * Current active/trialing/past_due subscription for the company.
     */
    public static function currentSubscription(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $scheduledIntent = PlanChangeIntent::where('company_id', $company->id)
            ->scheduled()
            ->first();

        return [
            'id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'interval' => $subscription->interval,
            'status' => $subscription->status,
            'provider' => $subscription->provider,
            'current_period_start' => $subscription->current_period_start?->toISOString(),
            'current_period_end' => $subscription->current_period_end?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
            'created_at' => $subscription->created_at->toISOString(),
            'scheduled_change' => $scheduledIntent ? [
                'id' => $scheduledIntent->id,
                'from_plan_key' => $scheduledIntent->from_plan_key,
                'to_plan_key' => $scheduledIntent->to_plan_key,
                'interval_from' => $scheduledIntent->interval_from,
                'interval_to' => $scheduledIntent->interval_to,
                'timing' => $scheduledIntent->timing,
                'effective_at' => $scheduledIntent->effective_at?->toISOString(),
            ] : null,
        ];
    }

    /**
     * Next invoice preview: plan + addons + tax + wallet + estimated amount_due.
     *
     * Uses the same tax/wallet logic as InvoiceIssuer::finalize() to produce
     * an honest estimate. Flagged with is_estimate=true.
     */
    public static function nextInvoicePreview(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $wallet = WalletLedger::ensureWallet($company);
        $currency = $wallet->currency;
        $walletBalance = WalletLedger::balance($company);
        $policy = PlatformBillingPolicy::instance();

        $planModel = Plan::where('key', $subscription->plan_key)->first();
        $planPrice = 0;
        $planData = null;

        if ($planModel) {
            $planPrice = $subscription->interval === 'yearly'
                ? ($planModel->price_yearly ?? 0)
                : ($planModel->price_monthly ?? 0);

            $planData = [
                'name' => $planModel->name,
                'price' => $planPrice,
                'interval' => $subscription->interval,
            ];
        }

        // Active addons with module name (batch lookup)
        $addonSubs = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->get();

        $addonModuleKeys = $addonSubs->pluck('module_key')->toArray();
        $addonModuleNames = ! empty($addonModuleKeys)
            ? PlatformModule::whereIn('key', $addonModuleKeys)->pluck('name', 'key')->toArray()
            : [];

        $addons = $addonSubs->map(fn ($addon) => [
            'module_key' => $addon->module_key,
            'name' => $addonModuleNames[$addon->module_key] ?? $addon->module_key,
            'price' => $addon->amount_cents,
        ])->toArray();

        $addonsTotal = array_sum(array_column($addons, 'price'));
        $subtotal = $planPrice + $addonsTotal;

        // Tax — resolved from company's LegalStatus (ADR-251)
        $taxRateBps = TaxResolver::resolveRateBps($company);
        $taxAmount = TaxResolver::compute($subtotal, $taxRateBps);
        $total = $subtotal + $taxAmount;

        // Wallet credit estimate — same logic as InvoiceIssuer::finalize()
        $estimatedWalletCredit = 0;
        if ($policy->auto_apply_wallet_credit && $total > 0 && $walletBalance > 0) {
            $estimatedWalletCredit = min($walletBalance, $total);
        }

        $estimatedAmountDue = $total - $estimatedWalletCredit;

        // Next billing date
        $nextBillingDate = $subscription->current_period_end?->toDateString();

        // Trial
        $trialRemainingDays = null;
        if ($subscription->status === 'trialing' && $subscription->trial_ends_at) {
            $trialRemainingDays = max(0, (int) now()->diffInDays($subscription->trial_ends_at, false));
        }

        return [
            'is_estimate' => true,
            'currency' => $currency,
            'plan' => $planData,
            'addons' => $addons,
            'subtotal' => $subtotal,
            'tax_rate_bps' => $taxRateBps,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'wallet_balance' => $walletBalance,
            'estimated_wallet_credit' => $estimatedWalletCredit,
            'estimated_amount_due' => $estimatedAmountDue,
            'next_billing_date' => $nextBillingDate,
            'trial_remaining_days' => $trialRemainingDays,
        ];
    }

    /**
     * Plan change preview: proration + addon recalc + tax + wallet.
     *
     * Shows the financial impact of switching from the current plan to a new plan
     * BEFORE the user confirms. Same calculation pipeline as PlanChangeExecutor.
     */
    public static function planChangePreview(Company $company, string $toPlanKey, string $toInterval = 'monthly'): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $policy = PlatformBillingPolicy::instance();
        $plans = PlanRegistry::definitions();
        $fromPlan = $plans[$subscription->plan_key] ?? null;
        $toPlan = $plans[$toPlanKey] ?? null;

        if (!$fromPlan || !$toPlan) {
            return null;
        }

        $fromLevel = $fromPlan['level'];
        $toLevel = $toPlan['level'];
        $fromInterval = $subscription->interval ?? 'monthly';
        $isUpgrade = $toLevel > $fromLevel;
        $isIntervalChange = $subscription->plan_key === $toPlanKey && $fromInterval !== $toInterval;

        // Interval-only changes use their own timing setting
        $timing = $isIntervalChange
            ? ($policy->interval_change_timing ?? 'immediate')
            : ($isUpgrade ? $policy->upgrade_timing : $policy->downgrade_timing);

        // Current plan price (for the subscription's current interval)
        $oldPriceCents = ProrationCalculator::resolvePriceCents($fromPlan, $fromInterval);
        $newPriceCents = ProrationCalculator::resolvePriceCents($toPlan, $toInterval);

        // Proration (only for immediate changes with a valid period)
        $proration = null;
        if ($timing === 'immediate'
            && $subscription->current_period_start
            && $subscription->current_period_end
            && $subscription->current_period_end->gt(now())
        ) {
            $proration = ProrationCalculator::compute(
                oldPriceCents: $oldPriceCents,
                newPriceCents: $newPriceCents,
                periodStart: CarbonImmutable::instance($subscription->current_period_start),
                periodEnd: CarbonImmutable::instance($subscription->current_period_end),
                changeDate: CarbonImmutable::now(),
            );
        }

        // Active addons: recalculate amounts for new plan + interval
        $activeAddons = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()
            ->get();

        $addonModuleKeys = $activeAddons->pluck('module_key')->toArray();
        $addonModuleNames = !empty($addonModuleKeys)
            ? PlatformModule::whereIn('key', $addonModuleKeys)->pluck('name', 'key')->toArray()
            : [];

        $addonLines = [];
        $addonsTotalCurrent = 0;
        $addonsTotalNew = 0;

        foreach ($activeAddons as $addon) {
            $module = PlatformModule::where('key', $addon->module_key)->first();
            $currentAmount = $addon->amount_cents;
            $newMonthly = $module ? ModuleQuoteCalculator::computeAmount($module, $toPlanKey) : $currentAmount;
            $newAmount = $toInterval === 'yearly' ? $newMonthly * 12 : $newMonthly;

            $addonLines[] = [
                'module_key' => $addon->module_key,
                'name' => $addonModuleNames[$addon->module_key] ?? $addon->module_key,
                'current_amount' => $currentAmount,
                'new_amount' => $newAmount,
                'difference' => $newAmount - $currentAmount,
            ];

            $addonsTotalCurrent += $currentAmount;
            $addonsTotalNew += $newAmount;
        }

        // Build subtotal for the new plan's next period
        $newPlanSubtotal = $newPriceCents + $addonsTotalNew;

        // Immediate change: the amount due NOW is the proration net + addon difference
        // End of period: amount due NOW is 0 (change happens later)
        $immediateDue = 0;
        if ($timing === 'immediate' && $proration) {
            $immediateDue = $proration['net'];
        }

        // Tax on the immediate amount — resolved from company's Market (ADR-254)
        $taxRateBps = TaxResolver::resolveRateBps($company);
        $immediateTax = $immediateDue > 0 ? TaxResolver::compute($immediateDue, $taxRateBps) : 0;
        $immediateTotal = $immediateDue + $immediateTax;

        // When proration is negative (downgrade credit), the credit goes to wallet
        $estimatedWalletCredit_added = 0;
        if ($immediateDue < 0) {
            $estimatedWalletCredit_added = abs($immediateDue);
        }

        // Wallet
        $walletBalance = WalletLedger::balance($company);
        $estimatedWalletDeduction = 0;
        if ($policy->auto_apply_wallet_credit && $immediateTotal > 0 && $walletBalance > 0) {
            $estimatedWalletDeduction = min($walletBalance, $immediateTotal);
        }
        $estimatedAmountDue = max(0, $immediateTotal - $estimatedWalletDeduction);

        // Next period preview (what the recurring invoice will look like)
        $nextPeriodTax = TaxResolver::compute($newPlanSubtotal, $taxRateBps);
        $nextPeriodTotal = $newPlanSubtotal + $nextPeriodTax;

        $wallet = WalletLedger::ensureWallet($company);

        // Fiscal context — market & legal status info for the preview
        $taxMode = $policy->tax_mode ?? 'none';
        $market = $company->market_key
            ? \App\Core\Markets\Market::where('key', $company->market_key)->first()
            : null;
        $legalStatus = ($company->legal_status_key && $company->market_key)
            ? \App\Core\Markets\LegalStatus::where('key', $company->legal_status_key)
                ->where('market_key', $company->market_key)
                ->first()
            : null;

        return [
            'is_estimate' => true,
            'timing' => $timing,
            'is_upgrade' => $isUpgrade,
            'is_interval_change' => $isIntervalChange,
            'currency' => $wallet->currency,

            // Fiscal context
            'tax_mode' => $taxMode,
            'tax_rate_bps' => $taxRateBps,
            'market_name' => $market?->name,
            'legal_status_name' => $legalStatus?->name,

            // Current plan
            'from_plan' => [
                'key' => $subscription->plan_key,
                'name' => $fromPlan['name'],
                'price' => $oldPriceCents,
                'interval' => $fromInterval,
            ],

            // New plan
            'to_plan' => [
                'key' => $toPlanKey,
                'name' => $toPlan['name'],
                'price' => $newPriceCents,
                'interval' => $toInterval,
            ],

            // Proration (null if end_of_period or no valid period)
            'proration' => $proration ? [
                'credit_old_plan' => $proration['credit'],
                'charge_new_plan' => $proration['charge'],
                'net' => $proration['net'],
                'days_remaining' => $proration['days_remaining'],
                'total_days' => $proration['total_days'],
            ] : null,

            // Addon impact
            'addons' => $addonLines,
            'addons_total_current' => $addonsTotalCurrent,
            'addons_total_new' => $addonsTotalNew,

            // Wallet (always shown)
            'wallet_balance' => $walletBalance,

            // Immediate financial impact
            'immediate' => [
                'subtotal' => $immediateDue,
                'tax_rate_bps' => $taxRateBps,
                'tax_amount' => $immediateTax,
                'total' => $immediateTotal,
                'wallet_deduction' => $estimatedWalletDeduction,
                'wallet_credit_added' => $estimatedWalletCredit_added,
                'estimated_amount_due' => $estimatedAmountDue,
            ],

            // Next recurring period preview
            'next_period' => [
                'plan_price' => $newPriceCents,
                'addons_total' => $addonsTotalNew,
                'subtotal' => $newPlanSubtotal,
                'tax_rate_bps' => $taxRateBps,
                'tax_amount' => $nextPeriodTax,
                'total' => $nextPeriodTotal,
                'interval' => $toInterval,
            ],
        ];
    }

}
