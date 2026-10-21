<?php

namespace App\Modules\Platform\Plans\Http;

use App\Core\Billing\ReadModels\PlanPricingGovernanceService;
use App\Modules\Platform\Plans\ReadModels\PlatformPlanReadModel;
use App\Modules\Platform\Plans\UseCases\MigrateSubscriptionPriceUseCase;
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

    /** ADR-588: governance impact of (optionally) changing a plan's public price — read-only. */
    public function pricingImpact(Request $request, string $key): JsonResponse
    {
        $interval = in_array($request->query('interval'), ['monthly', 'yearly'], true) ? $request->query('interval') : 'monthly';
        $proposed = $request->filled('proposed_cents') ? (int) $request->query('proposed_cents') : null;

        return response()->json(PlanPricingGovernanceService::impact($key, $interval, $proposed))
            ->header('Cache-Control', 'no-store, private');
    }

    /** ADR-588: selectable subscriptions for the migration wizard — read-only. */
    public function migrationSubscriptions(Request $request, string $key): JsonResponse
    {
        $interval = in_array($request->query('interval'), ['monthly', 'yearly'], true) ? $request->query('interval') : 'monthly';

        return response()->json(['subscriptions' => PlanPricingGovernanceService::subscriptionsForPlan($key, $interval)])
            ->header('Cache-Control', 'no-store, private');
    }

    /** ADR-588: preview an explicit tariff migration — NO financial effect. */
    public function migrationPreview(Request $request, MigrateSubscriptionPriceUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'subscription_ids' => ['required', 'array', 'min:1'],
            'subscription_ids.*' => ['integer'],
            'new_price_cents' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($useCase->preview($validated['subscription_ids'], $validated['new_price_cents']))
            ->header('Cache-Control', 'no-store, private');
    }

    /** ADR-588: apply an explicit, authorized tariff migration — audited, atomic, idempotent. */
    public function migrationApply(Request $request, MigrateSubscriptionPriceUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'subscription_ids' => ['required', 'array', 'min:1'],
            'subscription_ids.*' => ['integer'],
            'new_price_cents' => ['required', 'integer', 'min:0'],
            'timing' => ['required', 'in:immediate,at_renewal'],
            'effective_at' => ['nullable', 'date'],
        ]);

        try {
            $stats = $useCase->apply(
                $validated['subscription_ids'],
                $validated['new_price_cents'],
                $validated['timing'],
                isset($validated['effective_at']) ? \Carbon\Carbon::parse($validated['effective_at']) : null,
                $request->user()?->id,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($stats);
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
