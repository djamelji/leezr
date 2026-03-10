<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\PaymentPolicy;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PricingEngine;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
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
            'allowed_payment_methods' => PaymentPolicy::allowedMethods($company),
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
    /** ADR-324: nextInvoicePreview via PricingEngine. */
    public static function nextInvoicePreview(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->with('coupon')
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $breakdown = PricingEngine::forCurrentPeriod($subscription, $company);

        // Runtime: wallet estimation (not part of pricing)
        $walletBalance = WalletLedger::balance($company);
        $policy = PlatformBillingPolicy::instance();
        $estimatedWalletCredit = ($policy->auto_apply_wallet_credit && $breakdown->total > 0 && $walletBalance > 0)
            ? min($walletBalance, $breakdown->total)
            : 0;

        // Extract plan data from breakdown
        $planLine = $breakdown->planLine();
        $planData = $planLine ? [
            'name' => str_replace(' plan', '', $planLine->description),
            'price' => $planLine->unitAmount,
            'interval' => $subscription->interval,
        ] : null;

        // Extract addon data from breakdown
        $addons = array_map(fn ($l) => [
            'module_key' => $l->moduleKey,
            'name' => $l->description,
            'price' => $l->unitAmount,
        ], $breakdown->addonLines());

        // Trial
        $trialRemainingDays = null;
        if ($subscription->status === 'trialing' && $subscription->trial_ends_at) {
            $trialRemainingDays = max(0, (int) now()->diffInDays($subscription->trial_ends_at, false));
        }

        return [
            'is_estimate' => true,
            'currency' => $breakdown->currency,
            'plan' => $planData,
            'addons' => $addons,
            'coupon' => $breakdown->coupon?->toArray(),
            'subtotal' => $breakdown->subtotal,
            'tax_rate_bps' => $breakdown->taxRateBps,
            'tax_exemption_reason' => $breakdown->taxExemptionReason,
            'tax_amount' => $breakdown->taxAmount,
            'total' => $breakdown->total,
            'wallet_balance' => $walletBalance,
            'estimated_wallet_credit' => $estimatedWalletCredit,
            'estimated_amount_due' => $breakdown->total - $estimatedWalletCredit,
            'next_billing_date' => $subscription->current_period_end?->toDateString(),
            'trial_remaining_days' => $trialRemainingDays,
        ];
    }

    /**
     * Plan change preview: proration + addon recalc + tax + wallet.
     *
     * Shows the financial impact of switching from the current plan to a new plan
     * BEFORE the user confirms. Same calculation pipeline as PlanChangeExecutor.
     */
    /** ADR-324: planChangePreview via PricingEngine. */
    public static function planChangePreview(Company $company, string $toPlanKey, string $toInterval = 'monthly'): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->with('coupon')
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $pcb = PricingEngine::forPlanChange($company, $subscription, $toPlanKey, $toInterval);

        // Runtime: wallet
        $policy = PlatformBillingPolicy::instance();
        $walletBalance = WalletLedger::balance($company);

        // Immediate financial impact
        $immediateDue = $pcb->prorationDetails ? $pcb->prorationDetails['net'] : 0;
        $immediateTax = $pcb->immediate?->taxAmount ?? 0;
        $immediateTotal = $pcb->immediate?->total ?? 0;

        $estimatedWalletCreditAdded = $immediateDue < 0 ? abs($immediateDue) : 0;
        $estimatedWalletDeduction = 0;
        if ($policy->auto_apply_wallet_credit && $immediateTotal > 0 && $walletBalance > 0) {
            $estimatedWalletDeduction = min($walletBalance, $immediateTotal);
        }
        $estimatedAmountDue = max(0, $immediateTotal - $estimatedWalletDeduction);

        // Fiscal context
        $taxMode = $policy->tax_mode ?? 'none';
        $market = $company->market_key
            ? \App\Core\Markets\Market::where('key', $company->market_key)->first()
            : null;
        $legalStatus = ($company->legal_status_key && $company->market_key)
            ? \App\Core\Markets\LegalStatus::where('key', $company->legal_status_key)
                ->where('market_key', $company->market_key)
                ->first()
            : null;

        // Addon totals
        $addonsTotalCurrent = array_sum(array_column($pcb->addonLines, 'current_amount'));
        $addonsTotalNew = array_sum(array_column($pcb->addonLines, 'new_amount'));

        // Next period coupon discount
        $nextCouponDiscount = $pcb->nextPeriod->discountLine()?->amount ?? 0;

        return [
            'is_estimate' => true,
            'timing' => $pcb->timing,
            'is_upgrade' => $pcb->isUpgrade,
            'is_interval_change' => $pcb->isIntervalChange,
            'currency' => $pcb->currency,

            'tax_mode' => $taxMode,
            'tax_rate_bps' => $pcb->nextPeriod->taxRateBps,
            'tax_exemption_reason' => $pcb->nextPeriod->taxExemptionReason,
            'market_name' => $market?->name,
            'legal_status_name' => $legalStatus?->name,

            'from_plan' => $pcb->fromPlan,
            'to_plan' => $pcb->toPlan,

            'active_coupon' => $pcb->activeCoupon?->toArray(),

            'proration' => $pcb->prorationDetails,

            'addons' => $pcb->addonLines,
            'addons_total_current' => $addonsTotalCurrent,
            'addons_total_new' => $addonsTotalNew,

            'wallet_balance' => $walletBalance,

            'immediate' => [
                'subtotal' => $pcb->immediate?->subtotal ?? 0,
                'tax_rate_bps' => $pcb->immediate?->taxRateBps ?? 0,
                'tax_amount' => $immediateTax,
                'total' => $immediateTotal,
                'wallet_deduction' => $estimatedWalletDeduction,
                'wallet_credit_added' => $estimatedWalletCreditAdded,
                'estimated_amount_due' => $estimatedAmountDue,
            ],

            'next_period' => [
                'plan_price' => $pcb->toPlan['price'],
                'coupon_discount' => $nextCouponDiscount,
                'addons_total' => $addonsTotalNew,
                'subtotal' => $pcb->nextPeriod->subtotal,
                'tax_rate_bps' => $pcb->nextPeriod->taxRateBps,
                'tax_amount' => $pcb->nextPeriod->taxAmount,
                'total' => $pcb->nextPeriod->total,
                'interval' => $pcb->toPlan['interval'] ?? $toInterval,
            ],
        ];
    }
}
