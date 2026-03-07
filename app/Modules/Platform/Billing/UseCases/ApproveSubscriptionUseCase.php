<?php

namespace App\Modules\Platform\Billing\UseCases;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveSubscriptionUseCase
{
    public function __construct(
        private readonly BillingProvider $billing,
    ) {}

    /**
     * Approve a pending subscription: expire any existing active subscription,
     * change plan via billing provider, activate the new subscription.
     */
    public function execute(int $subscriptionId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::where('status', 'pending')->find($subscriptionId);

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'subscription' => ['Subscription not found or not in pending status.'],
                ]);
            }

            $company = $subscription->company;

            // Enforce one active subscription: expire any existing active
            Subscription::where('company_id', $company->id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'current_period_end' => now(), 'is_current' => null]);

            // Clear any other is_current
            Subscription::where('company_id', $company->id)
                ->where('is_current', 1)
                ->where('id', '!=', $subscription->id)
                ->update(['is_current' => null]);

            $this->billing->changePlan($company, $subscription->plan_key);

            $periodEnd = $subscription->interval === 'yearly'
                ? now()->addYear()
                : now()->addMonth();

            $subscription->update([
                'status' => 'active',
                'is_current' => 1,
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
            ]);

            return $subscription->fresh()->load('company:id,name,slug');
        });
    }
}
