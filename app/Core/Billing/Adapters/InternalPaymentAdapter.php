<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;

/**
 * Internal payment adapter — no external payment processing.
 * Creates subscriptions in 'pending' state for admin approval.
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

    public function createCheckout(Company $company, string $planKey): CheckoutResult
    {
        $existingPending = Subscription::where('company_id', $company->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return new CheckoutResult(
                mode: 'internal',
                message: 'You already have a pending upgrade request. Please wait for administrator approval.',
            );
        }

        $plan = Plan::where('key', $planKey)->first();
        $trialDays = $plan?->trial_days ?? 0;

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => $planKey,
            'status' => $trialDays > 0 ? 'trialing' : 'pending',
            'provider' => 'internal',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'current_period_start' => $trialDays > 0 ? now() : null,
            'current_period_end' => $trialDays > 0 ? now()->addDays($trialDays) : null,
        ]);

        return new CheckoutResult(
            mode: 'internal',
            message: 'Your upgrade request has been submitted. An administrator will review it shortly.',
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
}
