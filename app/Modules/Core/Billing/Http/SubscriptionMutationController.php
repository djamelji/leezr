<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\InvoicePayNowService;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\SubscriptionCanceller;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * ADR-135 D1: Company subscription mutation endpoints.
 *
 * Zero business logic here — orchestration only:
 *   1. Validate input
 *   2. Enforce policy constraints
 *   3. Delegate to service
 *   4. Audit
 *   5. Return response
 */
class SubscriptionMutationController
{
    /**
     * POST /billing/plan-change
     *
     * Policy-driven: timing derived from PlatformBillingPolicy.
     * Source of truth: PlanChangeIntent (never direct subscription mutation).
     */
    public function planChange(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
            'to_plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'to_interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $company = $request->attributes->get('company');

        // Idempotency: if intent already exists for this key, return it directly
        $existingIntent = PlanChangeIntent::where('idempotency_key', $validated['idempotency_key'])->first();

        if ($existingIntent) {
            return response()->json([
                'message' => $existingIntent->isExecuted() ? 'Plan change executed.' : 'Plan change scheduled.',
                'intent' => $existingIntent,
            ]);
        }

        $policy = PlatformBillingPolicy::instance();

        // Verify active subscription exists
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription.'], 422);
        }

        // Reject same-plan change
        if ($subscription->plan_key === $validated['to_plan_key']) {
            return response()->json(['message' => 'Already on this plan.'], 422);
        }

        // Derive timing from policy
        $isUpgrade = PlanRegistry::level($validated['to_plan_key'])
            > PlanRegistry::level($subscription->plan_key);
        $policyTiming = $isUpgrade ? $policy->upgrade_timing : $policy->downgrade_timing;

        // Audit: requested
        $audit->logCompany(
            $company->id,
            AuditAction::PLAN_CHANGE_REQUESTED,
            'subscription',
            (string) $subscription->id,
            [
                'metadata' => [
                    'from_plan' => $subscription->plan_key,
                    'to_plan' => $validated['to_plan_key'],
                    'timing' => $policyTiming,
                    'idempotency_key' => $validated['idempotency_key'],
                ],
            ],
        );

        try {
            $intent = PlanChangeExecutor::schedule(
                company: $company,
                toPlanKey: $validated['to_plan_key'],
                toInterval: $validated['to_interval'] ?? 'monthly',
                timing: $policyTiming,
                idempotencyKey: $validated['idempotency_key'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Audit: executed (if immediate)
        if ($intent->isExecuted()) {
            $audit->logCompany(
                $company->id,
                AuditAction::PLAN_CHANGE_EXECUTED,
                'plan_change_intent',
                (string) $intent->id,
                [
                    'metadata' => [
                        'from_plan' => $intent->from_plan_key,
                        'to_plan' => $intent->to_plan_key,
                        'timing' => $policyTiming,
                        'proration' => $intent->proration_snapshot,
                    ],
                ],
            );
        }

        return response()->json([
            'message' => $intent->isExecuted()
                ? 'Plan change executed.'
                : 'Plan change scheduled.',
            'intent' => $intent,
        ]);
    }

    /**
     * PUT /billing/subscription/cancel
     *
     * Timing derived from PlatformBillingPolicy.downgrade_timing:
     *   - immediate: subscription cancelled now
     *   - end_of_period: cancel_at_period_end = true
     */
    public function cancel(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $company = $request->attributes->get('company');

        // Audit: requested
        $audit->logCompany(
            $company->id,
            AuditAction::CANCEL_REQUESTED,
            'company',
            (string) $company->id,
            [
                'metadata' => [
                    'idempotency_key' => $validated['idempotency_key'],
                ],
            ],
        );

        try {
            $result = SubscriptionCanceller::cancel($company, $validated['idempotency_key']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Audit: executed (if not idempotent replay)
        if (!$result['idempotent']) {
            $audit->logCompany(
                $company->id,
                AuditAction::CANCEL_EXECUTED,
                'subscription',
                (string) $result['subscription']->id,
                [
                    'metadata' => [
                        'timing' => $result['timing'],
                        'status' => $result['subscription']->status,
                        'cancel_at_period_end' => $result['subscription']->cancel_at_period_end,
                    ],
                ],
            );
        }

        return response()->json([
            'message' => $result['timing'] === 'immediate'
                ? 'Subscription cancelled.'
                : 'Subscription will cancel at period end.',
            'subscription' => $result['subscription'],
            'timing' => $result['timing'],
        ]);
    }

    /**
     * POST /billing/pay-now
     *
     * Pays open/overdue invoices using wallet credit.
     * Wallet-first policy respected.
     */
    public function payNow(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $company = $request->attributes->get('company');
        $userId = $request->user()?->id;

        try {
            $result = InvoicePayNowService::payNow(
                $company,
                $validated['idempotency_key'],
                $userId,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Audit
        if ($result['invoices_paid'] > 0) {
            $audit->logCompany(
                $company->id,
                AuditAction::PAID_NOW,
                'company',
                (string) $company->id,
                [
                    'metadata' => [
                        'invoices_paid' => $result['invoices_paid'],
                        'total_amount' => $result['total_amount'],
                        'wallet_used' => $result['wallet_used'],
                        'paid_invoice_ids' => $result['paid_invoice_ids'],
                        'idempotency_key' => $validated['idempotency_key'],
                    ],
                ],
            );
        }

        return response()->json([
            'message' => "Paid {$result['invoices_paid']} invoice(s).",
            'invoices_paid' => $result['invoices_paid'],
            'total_amount' => $result['total_amount'],
            'wallet_used' => $result['wallet_used'],
        ]);
    }
}
