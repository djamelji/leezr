<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\InvoicePayNowService;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlanChangeTimingResolver;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\SubscriptionCanceller;
use App\Core\Billing\WalletLedger;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** ADR-135 D1: Company subscription mutation endpoints. */
class SubscriptionMutationController
{
    public function planChange(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
            'to_plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'to_interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $company = $request->attributes->get('company');

        $existingIntent = PlanChangeIntent::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existingIntent) {
            return response()->json([
                'message' => $existingIntent->isExecuted() ? 'Plan change executed.' : 'Plan change scheduled.',
                'intent' => $existingIntent,
            ]);
        }

        $policy = PlatformBillingPolicy::instance();

        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription.'], 422);
        }

        $toInterval = $validated['to_interval'] ?? $policy->default_billing_interval;

        if ($subscription->plan_key === $validated['to_plan_key']
            && ($subscription->interval ?? 'monthly') === $toInterval) {
            return response()->json(['message' => 'Already on this plan and interval.'], 422);
        }

        $resolved = PlanChangeTimingResolver::resolve($subscription, $validated['to_plan_key'], $toInterval, $policy);
        $policyTiming = $resolved['timing'];

        $audit->logCompany($company->id, AuditAction::PLAN_CHANGE_REQUESTED, 'subscription', (string) $subscription->id, [
            'metadata' => [
                'from_plan' => $subscription->plan_key,
                'to_plan' => $validated['to_plan_key'],
                'timing' => $policyTiming,
                'idempotency_key' => $validated['idempotency_key'],
            ],
        ]);

        try {
            $intent = PlanChangeExecutor::schedule(
                company: $company,
                toPlanKey: $validated['to_plan_key'],
                toInterval: $toInterval,
                timing: $policyTiming,
                idempotencyKey: $validated['idempotency_key'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($intent->isExecuted()) {
            $audit->logCompany($company->id, AuditAction::PLAN_CHANGE_EXECUTED, 'plan_change_intent', (string) $intent->id, [
                'metadata' => [
                    'from_plan' => $intent->from_plan_key,
                    'to_plan' => $intent->to_plan_key,
                    'timing' => $policyTiming,
                    'proration' => $intent->proration_snapshot,
                ],
            ]);
        }

        return response()->json([
            'message' => $intent->isExecuted() ? 'Plan change executed.' : 'Plan change scheduled.',
            'intent' => $intent,
        ]);
    }

    public function cancel(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $company = $request->attributes->get('company');

        $audit->logCompany($company->id, AuditAction::CANCEL_REQUESTED, 'company', (string) $company->id, [
            'metadata' => ['idempotency_key' => $validated['idempotency_key']],
        ]);

        try {
            $result = SubscriptionCanceller::cancel($company, $validated['idempotency_key']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (!$result['idempotent']) {
            $audit->logCompany($company->id, AuditAction::CANCEL_EXECUTED, 'subscription', (string) $result['subscription']->id, [
                'metadata' => [
                    'timing' => $result['timing'],
                    'status' => $result['subscription']->status,
                    'cancel_at_period_end' => $result['subscription']->cancel_at_period_end,
                ],
            ]);
        }

        $sub = $result['subscription'];

        return response()->json([
            'message' => $result['timing'] === 'immediate' ? 'Subscription cancelled.' : 'Subscription will cancel at period end.',
            'subscription' => $sub,
            'timing' => $result['timing'],
            'period_end' => $sub->current_period_end?->toDateString(),
            'active_addons_count' => CompanyAddonSubscription::where('company_id', $company->id)->active()->count(),
        ]);
    }

    public function cancelPreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $sub = Subscription::where('company_id', $company->id)->whereIn('status', ['active', 'trialing'])->latest()->first();
        if (! $sub) {
            return response()->json(['message' => 'No active subscription.'], 404);
        }

        $activeAddons = CompanyAddonSubscription::where('company_id', $company->id)->active()->get();

        return response()->json([
            'timing' => PlatformBillingPolicy::instance()->downgrade_timing,
            'period_end' => $sub->current_period_end?->toDateString(),
            'plan_name' => $sub->plan_key,
            'interval' => $sub->interval,
            'active_addons' => $activeAddons->map(fn ($a) => ['module_key' => $a->module_key, 'amount_cents' => $a->amount_cents])->values(),
            'wallet_balance' => WalletLedger::balance($company),
        ]);
    }

    public function payNow(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $company = $request->attributes->get('company');

        try {
            $result = InvoicePayNowService::payNow($company, $validated['idempotency_key'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['invoices_paid'] > 0) {
            $audit->logCompany($company->id, AuditAction::PAID_NOW, 'company', (string) $company->id, [
                'metadata' => [
                    'invoices_paid' => $result['invoices_paid'],
                    'total_amount' => $result['total_amount'],
                    'wallet_used' => $result['wallet_used'],
                    'paid_invoice_ids' => $result['paid_invoice_ids'],
                    'idempotency_key' => $validated['idempotency_key'],
                ],
            ]);
        }

        return response()->json([
            'message' => "Paid {$result['invoices_paid']} invoice(s).",
            'invoices_paid' => $result['invoices_paid'],
            'total_amount' => $result['total_amount'],
            'wallet_used' => $result['wallet_used'],
        ]);
    }

    public function cancelPlanChange(Request $request, AuditLogger $audit): JsonResponse
    {
        $company = $request->attributes->get('company');

        $intent = PlanChangeIntent::where('company_id', $company->id)
            ->scheduled()
            ->first();

        if (!$intent) {
            return response()->json(['message' => 'No scheduled plan change to cancel.'], 404);
        }

        $intent->update(['status' => 'cancelled']);

        $audit->logCompany($company->id, AuditAction::PLAN_CHANGE_CANCELLED, 'plan_change_intent', (string) $intent->id, [
            'metadata' => [
                'from_plan' => $intent->from_plan_key,
                'to_plan' => $intent->to_plan_key,
            ],
        ]);

        return response()->json(['message' => 'Scheduled plan change cancelled.']);
    }

    public function setBillingDay(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'billing_anchor_day' => ['required', 'integer', 'in:1,5,10,15,20,25'],
        ]);

        $subscription = Subscription::where('company_id', $company->id)->isCurrent()->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription.'], 422);
        }

        $subscription->update(['billing_anchor_day' => $validated['billing_anchor_day']]);

        return response()->json([
            'message' => 'Billing day updated.',
            'billing_anchor_day' => $validated['billing_anchor_day'],
        ]);
    }
}
