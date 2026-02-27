<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-135 D1: Policy-driven subscription cancellation.
 *
 * Timing derived from PlatformBillingPolicy.downgrade_timing:
 *   - immediate: status → cancelled now
 *   - end_of_period: cancel_at_period_end → true
 *
 * Idempotency: via cancel_idempotency_key stored in subscription.metadata.
 */
class SubscriptionCanceller
{
    /**
     * Cancel a company's active subscription.
     *
     * @return array{subscription: Subscription, timing: string, idempotent: bool}
     *
     * @throws RuntimeException If no active subscription
     */
    public static function cancel(Company $company, string $idempotencyKey): array
    {
        $policy = PlatformBillingPolicy::instance();
        $timing = $policy->downgrade_timing;

        // Idempotency: check ANY subscription (including cancelled) with matching key
        $anySubscription = Subscription::where('company_id', $company->id)
            ->latest()
            ->first();

        if ($anySubscription) {
            $meta = $anySubscription->metadata ?? [];

            if (isset($meta['cancel_idempotency_key']) && $meta['cancel_idempotency_key'] === $idempotencyKey) {
                return [
                    'subscription' => $anySubscription,
                    'timing' => $timing,
                    'idempotent' => true,
                ];
            }
        }

        // Look for active subscription to cancel
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            throw new RuntimeException('No active subscription.');
        }

        return DB::transaction(function () use ($subscription, $timing, $idempotencyKey, $meta) {
            $subscription = Subscription::where('id', $subscription->id)->lockForUpdate()->first();

            $newMeta = array_merge($meta, ['cancel_idempotency_key' => $idempotencyKey]);

            if ($timing === 'immediate') {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => false,
                    'metadata' => $newMeta,
                ]);
            } else {
                // end_of_period
                $subscription->update([
                    'cancel_at_period_end' => true,
                    'metadata' => $newMeta,
                ]);
            }

            return [
                'subscription' => $subscription->fresh(),
                'timing' => $timing,
                'idempotent' => false,
            ];
        });
    }
}
