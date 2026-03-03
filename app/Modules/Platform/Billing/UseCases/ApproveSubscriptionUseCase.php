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
                ->update(['status' => 'expired', 'current_period_end' => now()]);

            $this->billing->changePlan($company, $subscription->plan_key);

            $subscription->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addYear(),
            ]);

            return $subscription->fresh()->load('company:id,name,slug');
        });
    }
}
