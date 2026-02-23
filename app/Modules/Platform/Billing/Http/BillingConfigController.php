<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\ReadModels\SubscriptionReadModel;
use App\Core\Billing\Subscription;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingConfigController
{
    public function providers(PaymentGatewayManager $manager): JsonResponse
    {
        return response()->json(['providers' => $manager->availableProviders()]);
    }

    public function showConfig(): JsonResponse
    {
        $settings = PlatformSetting::instance();

        return response()->json([
            'billing' => $settings->billing ?? ['driver' => 'null', 'config' => []],
        ]);
    }

    public function updateConfig(Request $request, PaymentGatewayManager $manager): JsonResponse
    {
        $availableKeys = collect($manager->availableProviders())->pluck('key')->all();

        $validated = $request->validate([
            'driver' => ['required', 'string', 'in:' . implode(',', $availableKeys)],
            'config' => ['sometimes', 'array'],
        ]);

        $settings = PlatformSetting::instance();
        $settings->update(['billing' => $validated]);

        return response()->json(['billing' => $settings->billing]);
    }

    public function subscriptions(): JsonResponse
    {
        return response()->json(SubscriptionReadModel::list());
    }

    public function approveSubscription(int $id, BillingProvider $billing): JsonResponse
    {
        return DB::transaction(function () use ($id, $billing) {
            $subscription = Subscription::where('status', 'pending')->findOrFail($id);
            $company = $subscription->company;

            // Enforce one active subscription: expire any existing active
            Subscription::where('company_id', $company->id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'current_period_end' => now()]);

            $billing->changePlan($company, $subscription->plan_key);

            $subscription->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addYear(),
            ]);

            return response()->json([
                'message' => 'Subscription approved.',
                'subscription' => $subscription->fresh()->load('company:id,name,slug'),
            ]);
        });
    }

    public function rejectSubscription(int $id): JsonResponse
    {
        $subscription = Subscription::where('status', 'pending')->findOrFail($id);
        $subscription->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Subscription rejected.',
            'subscription' => $subscription->fresh()->load('company:id,name,slug'),
        ]);
    }

    private const POLICY_DEFAULTS = [
        'payment_required' => false,
        'admin_approval_required' => true,
        'annual_only' => false,
        'currency' => 'usd',
        'vat_enabled' => false,
        'vat_rate' => 0,
    ];

    public function policies(): JsonResponse
    {
        $settings = PlatformSetting::instance();
        $billing = $settings->billing ?? [];

        return response()->json([
            'policies' => array_merge(self::POLICY_DEFAULTS, $billing['policies'] ?? []),
        ]);
    }

    public function updatePolicies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_required' => ['required', 'boolean'],
            'admin_approval_required' => ['required', 'boolean'],
            'annual_only' => ['required', 'boolean'],
            'currency' => ['required', 'string', 'in:usd,eur,gbp'],
            'vat_enabled' => ['required', 'boolean'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $settings = PlatformSetting::instance();
        $billing = $settings->billing ?? ['driver' => 'null', 'config' => []];
        $billing['policies'] = $validated;
        $settings->update(['billing' => $billing]);

        return response()->json([
            'message' => 'Payment policies updated.',
            'policies' => $validated,
        ]);
    }
}
