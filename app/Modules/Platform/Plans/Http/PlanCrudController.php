<?php

namespace App\Modules\Platform\Plans\Http;

use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use App\Core\Plans\ReadModels\PlanDetailReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanCrudController
{
    public function index(): JsonResponse
    {
        $plans = Plan::withCount(['companies' => fn ($q) => $q])
            ->orderBy('level')
            ->get()
            ->map(fn (Plan $plan) => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]));

        return response()->json($plans);
    }

    public function show(string $key, Request $request): JsonResponse
    {
        $plan = Plan::where('key', $key)->firstOrFail();

        $companiesPage = (int) $request->query('companies_page', 1);

        return response()->json([
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
                'companies_count' => Company::where('plan_key', $key)->count(),
            ]),
            'companies' => PlanDetailReadModel::companiesForPlan($key, 15),
        ]);
    }

    public function store(Request $request): JsonResponse
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
        ]);

        // Convert dollars to cents for storage
        $validated['price_monthly'] = (int) round($validated['price_monthly'] * 100);
        $validated['price_yearly'] = (int) round($validated['price_yearly'] * 100);

        $plan = Plan::create($validated);

        PlanRegistry::clearCache();

        return response()->json([
            'message' => 'Plan created.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:30', "unique:plans,key,{$plan->id}", 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'min:0'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'is_popular' => ['boolean'],
            'feature_labels' => ['nullable', 'array'],
            'feature_labels.*' => ['string'],
            'limits' => ['nullable', 'array'],
        ]);

        // Convert dollars to cents for storage
        $validated['price_monthly'] = (int) round($validated['price_monthly'] * 100);
        $validated['price_yearly'] = (int) round($validated['price_yearly'] * 100);

        $plan->update($validated);

        PlanRegistry::clearCache();

        return response()->json([
            'message' => 'Plan updated.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ]);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        // Prevent deactivating a plan that companies are using
        if ($plan->is_active) {
            $count = Company::where('plan_key', $plan->key)->count();

            if ($count > 0) {
                return response()->json([
                    'message' => "Cannot deactivate: {$count} companies are using this plan.",
                    'companies_count' => $count,
                ], 422);
            }
        }

        $plan->update(['is_active' => !$plan->is_active]);

        PlanRegistry::clearCache();

        return response()->json([
            'message' => $plan->is_active ? 'Plan activated.' : 'Plan deactivated.',
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]),
        ]);
    }
}
