<?php

namespace App\Modules\Platform\Plans\Http;

use App\Modules\Platform\Plans\ReadModels\PlatformPlanReadModel;
use App\Modules\Platform\Plans\UseCases\TogglePlanActiveUseCase;
use App\Modules\Platform\Plans\UseCases\UpsertPlanUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanCrudController
{
    public function index(): JsonResponse
    {
        return response()->json(PlatformPlanReadModel::catalog());
    }

    public function show(string $key): JsonResponse
    {
        return response()->json(PlatformPlanReadModel::detail($key));
    }

    public function store(Request $request, UpsertPlanUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:30', 'unique:plans,key', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'min:0'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'is_popular' => ['boolean'],
            'feature_labels' => ['nullable', 'array'],
            'feature_labels.*' => ['string'],
            'limits' => ['nullable', 'array'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $plan = $useCase->execute(null, $validated);

        return response()->json([
            'message' => 'Plan created.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ], 201);
    }

    public function update(Request $request, int $id, UpsertPlanUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:30', "unique:plans,key,{$id}", 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'min:0'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'is_popular' => ['boolean'],
            'feature_labels' => ['nullable', 'array'],
            'feature_labels.*' => ['string'],
            'limits' => ['nullable', 'array'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $plan = $useCase->execute($id, $validated);

        return response()->json([
            'message' => 'Plan updated.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ]);
    }

    public function toggleActive(int $id, TogglePlanActiveUseCase $useCase): JsonResponse
    {
        $plan = $useCase->execute($id);

        return response()->json([
            'message' => $plan->is_active ? 'Plan activated.' : 'Plan deactivated.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ]);
    }
}
