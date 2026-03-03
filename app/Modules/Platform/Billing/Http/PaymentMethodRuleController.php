<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\ReadModels\PlatformPaymentGovernanceReadService;
use App\Modules\Platform\Billing\PaymentGovernanceCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodRuleController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['provider_key', 'method_key', 'is_active']);

        return response()->json([
            'rules' => PlatformPaymentGovernanceReadService::listRules($filters),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $providerKeys = array_keys(PaymentRegistry::all());

        $validated = $request->validate([
            'method_key' => ['required', 'string', 'max:50'],
            'provider_key' => ['required', 'string', 'max:30', Rule::in($providerKeys)],
            'market_key' => ['nullable', 'string', 'max:10'],
            'plan_key' => ['nullable', 'string', 'max:30'],
            'interval' => ['nullable', 'string', Rule::in(['monthly', 'yearly'])],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'constraints' => ['nullable', 'array'],
        ]);

        $rule = PaymentGovernanceCrudService::createRule($validated);

        return response()->json([
            'message' => 'Payment method rule created.',
            'rule' => $rule,
        ], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $providerKeys = array_keys(PaymentRegistry::all());

        $validated = $request->validate([
            'method_key' => ['sometimes', 'string', 'max:50'],
            'provider_key' => ['sometimes', 'string', 'max:30', Rule::in($providerKeys)],
            'market_key' => ['nullable', 'string', 'max:10'],
            'plan_key' => ['nullable', 'string', 'max:30'],
            'interval' => ['nullable', 'string', Rule::in(['monthly', 'yearly'])],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'constraints' => ['nullable', 'array'],
        ]);

        $rule = PaymentGovernanceCrudService::updateRule($id, $validated);

        return response()->json([
            'message' => 'Payment method rule updated.',
            'rule' => $rule,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        PaymentGovernanceCrudService::deleteRule($id);

        return response()->json([
            'message' => 'Payment method rule deleted.',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'market_key' => ['nullable', 'string'],
            'plan_key' => ['nullable', 'string'],
            'interval' => ['nullable', 'string'],
        ]);

        $methods = PlatformPaymentGovernanceReadService::previewMethods(
            $validated['market_key'] ?? null,
            $validated['plan_key'] ?? null,
            $validated['interval'] ?? null,
        );

        return response()->json([
            'methods' => $methods,
        ]);
    }
}
