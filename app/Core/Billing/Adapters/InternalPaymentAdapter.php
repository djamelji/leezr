<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;

/**
 * Internal payment adapter — no external payment processing.
 *
 * Behaviour depends on PlatformBillingPolicy.admin_approval_required:
 *   false (default SaaS) → subscription created as active/trialing immediately
 *   true  (manual mode)  → subscription created as pending for admin approval
 */
class InternalPaymentAdapter implements PaymentProviderAdapter
{
    public function key(): string
    {
        return 'internal';
    }

    public function availableMethods(): array
    {
        return ['manual'];
    }

    public function healthCheck(): HealthResult
    {
        return new HealthResult('healthy');
    }

    public function createCheckout(Company $company, string $planKey, string $interval = 'monthly'): CheckoutResult
    {
        $policy = PlatformBillingPolicy::instance();
        $requiresApproval = $policy->admin_approval_required;

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

        if ($trialDays > 0) {
            $status = 'trialing';
        } elseif ($requiresApproval) {
            $status = 'pending';
        } else {
            $status = 'active';
        }

        $isCurrent = in_array($status, ['active', 'trialing']);

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
            'provider' => 'internal',
            'is_current' => $isCurrent ? 1 : null,
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'current_period_start' => $isCurrent ? now() : null,
            'current_period_end' => $isCurrent
                ? ($trialDays > 0 ? now()->addDays($trialDays) : $periodEnd)
                : null,
        ]);

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
        $subscription->update(['status' => 'cancelled', 'is_current' => null]);
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult
    {
        return new WebhookHandlingResult(handled: false);
    }

    public function refund(string $providerPaymentId, int $amount, array $metadata = []): array
    {
        throw new \RuntimeException('Internal provider does not support refunds.');
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        // No-op — internal provider has no external webhooks.
    }

    public function collectInvoice(Invoice $invoice, Company $company, array $metadata = []): array
    {
        throw new \RuntimeException('Internal provider does not support provider collection.');
    }

    public function getDashboardLinks(
        ?string $customerId = null,
        ?string $subscriptionId = null,
        ?string $invoiceId = null,
        ?string $paymentId = null,
    ): array {
        return [
            'customer_url' => null,
            'subscription_url' => null,
            'invoice_url' => null,
            'payment_url' => null,
        ];
    }
}
