<?php

namespace App\Modules\Platform\Plans\UseCases;

use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;

class UpsertPlanUseCase
{
    /**
     * Create or update a plan.
     *
     * Handles dollar→cents conversion and cache invalidation.
     */
    public function execute(?int $id, array $validated): Plan
    {
        // Convert dollars to cents for storage
        $validated['price_monthly'] = (int) round($validated['price_monthly'] * 100);
        $validated['price_yearly'] = (int) round($validated['price_yearly'] * 100);

        if ($id === null) {
            $plan = Plan::create($validated);
        } else {
            $plan = Plan::findOrFail($id);
            $plan->update($validated);
        }

        PlanRegistry::clearCache();

        return $plan;
    }
}
