<?php

namespace App\Core\Plans\ReadModels;

use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PlanDetailReadModel
{
    public static function companiesForPlan(string $planKey, int $perPage = 15): LengthAwarePaginator
    {
        return Company::where('plan_key', $planKey)
            ->with(['subscriptions' => fn ($q) => $q->latest()->limit(1)])
            ->select('id', 'name', 'slug', 'plan_key', 'created_at')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
