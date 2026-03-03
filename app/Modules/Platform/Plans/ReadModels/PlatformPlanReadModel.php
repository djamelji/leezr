<?php

namespace App\Modules\Platform\Plans\ReadModels;

use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Core\Plans\ReadModels\PlanDetailReadModel;

class PlatformPlanReadModel
{
    public static function catalog(): array
    {
        return Plan::withCount('companies')
            ->orderBy('level')
            ->get()
            ->map(fn (Plan $plan) => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
            ]))
            ->all();
    }

    public static function detail(string $key): array
    {
        $plan = Plan::where('key', $key)->firstOrFail();

        return [
            'plan' => array_merge($plan->toArray(), [
                'price_monthly_dollars' => $plan->priceMonthlyDollars(),
                'price_yearly_dollars' => $plan->priceYearlyDollars(),
                'companies_count' => Company::where('plan_key', $key)->count(),
            ]),
            'companies' => PlanDetailReadModel::companiesForPlan($key, 15),
        ];
    }
}
