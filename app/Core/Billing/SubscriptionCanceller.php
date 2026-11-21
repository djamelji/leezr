<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                    'is_current' => null,
                    'cancel_at_period_end' => false,
                    'metadata' => $newMeta,
                ]);

                // Deactivate all active addons
                $deactivated = CompanyAddonSubscription::where('company_id', $subscription->company_id)
                    ->active()
                    ->update(['deactivated_at' => now()]);

                if ($deactivated > 0) {
                    Log::channel('billing')->info('Addons deactivated on subscription cancel', [
                        'company_id' => $subscription->company_id,
                        'count' => $deactivated,
                    ]);
                }
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

    /**
     * ADR-845 (M8) — undo a SCHEDULED cancellation (cancel_at_period_end) while the period is still running.
     * Single rule for Company and Platform (was inline in CompanySubscriptionAdminController). An immediate
     * cancellation (status already cancelled) cannot be undone here : a new subscription is needed.
     */
    public static function undoScheduled(Company $company): ?Subscription
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($company) {
            $subscription = Subscription::where('company_id', $company->id)
                ->where('cancel_at_period_end', true)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->lockForUpdate()
                ->latest()
                ->first();
            if (! $subscription) {
                return null;
            }
            $subscription->update(['cancel_at_period_end' => false]);

            return $subscription->fresh();
        });
    }
}
