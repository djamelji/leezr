<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\ReadModels\SubscriptionReadModel;
use App\Modules\Platform\Billing\BillingConfigCrudService;
use App\Modules\Platform\Billing\UseCases\ApproveSubscriptionUseCase;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $billing = BillingConfigCrudService::updateConfig($validated);

        return response()->json(['billing' => $billing]);
    }

    public function subscriptions(): JsonResponse
    {
        return response()->json(SubscriptionReadModel::list());
    }

    public function approveSubscription(int $id, ApproveSubscriptionUseCase $useCase): JsonResponse
    {
        $subscription = $useCase->execute($id);

        return response()->json([
            'message' => 'Subscription approved.',
            'subscription' => $subscription,
        ]);
    }

    public function rejectSubscription(int $id): JsonResponse
    {
        $subscription = BillingConfigCrudService::rejectSubscription($id);

        return response()->json([
            'message' => 'Subscription rejected.',
            'subscription' => $subscription,
        ]);
    }

    private const POLICY_DEFAULTS = [
        'payment_required' => false,
        'annual_only' => false,
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
            'annual_only' => ['required', 'boolean'],
            'vat_enabled' => ['required', 'boolean'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $policies = BillingConfigCrudService::updatePolicies($validated);

        return response()->json([
            'message' => 'Payment policies updated.',
            'policies' => $policies,
        ]);
    }
}
