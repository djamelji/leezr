<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\PaymentGatewayProvider;
use App\Core\Models\Company;
use App\Core\Plans\Plan;

/**
 * Default payment gateway — no external payment processing.
 *
 * Behaviour depends on PlatformBillingPolicy.admin_approval_required:
 *   false (default SaaS) → subscription created as active/trialing immediately
 *   true  (manual mode)  → subscription created as pending for admin approval
 */
class NullPaymentGateway implements PaymentGatewayProvider
{
    public function createCheckout(Company $company, string $planKey, string $interval = 'monthly'): CheckoutResult
    {
        $policy = PlatformBillingPolicy::instance();
        $requiresApproval = $policy->admin_approval_required;

        // Guard: reject if company already has a pending subscription
        if ($requiresApproval) {
            $existingPending = Subscription::where('company_id', $company->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingPending) {
                return new CheckoutResult(
                    mode: 'internal',
                    message: 'You already have a pending upgrade request. Please wait for administrator approval.',
                );
            }
        }

        $plan = Plan::where('key', $planKey)->first();
        $trialDays = $plan?->trial_days ?? 0;

        // Determine status based on policy + trial
        if ($trialDays > 0) {
            $status = 'trialing';
        } elseif ($requiresApproval) {
            $status = 'pending';
        } else {
            $status = 'active';
        }

        $isCurrent = in_array($status, ['active', 'trialing']);

        // Deactivate previous current subscription
        if ($isCurrent) {
            Subscription::where('company_id', $company->id)
                ->where('is_current', 1)
                ->update(['is_current' => null]);
        }

        $periodEnd = $interval === 'yearly' ? now()->addYear() : now()->addMonth();

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => $planKey,
            'interval' => $interval,
            'status' => $status,
            'provider' => 'null',
            'is_current' => $isCurrent ? 1 : null,
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'current_period_start' => $isCurrent ? now() : null,
            'current_period_end' => $isCurrent
                ? ($trialDays > 0 ? now()->addDays($trialDays) : $periodEnd)
                : null,
        ]);

        // Auto-mode: sync company plan immediately
        if ($status === 'active' || $status === 'trialing') {
            $company->update(['plan_key' => $planKey]);
        }

        $message = match ($status) {
            'pending' => 'Your upgrade request has been submitted. An administrator will review it shortly.',
            'trialing' => "Your trial has started. You have {$trialDays} days to try the {$plan->name} plan.",
            default => "Your plan has been upgraded to {$plan->name}.",
        };

        return new CheckoutResult(
            mode: 'internal',
            message: $message,
            subscriptionId: $subscription->id,
        );
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        return null;
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $subscription->update(['status' => 'cancelled']);
    }

    public function key(): string
    {
        return 'null';
    }
}
