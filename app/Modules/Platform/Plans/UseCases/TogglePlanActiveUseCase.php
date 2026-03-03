<?php

namespace App\Modules\Platform\Plans\UseCases;

use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Illuminate\Validation\ValidationException;

class TogglePlanActiveUseCase
{
    /**
     * Toggle plan active status.
     *
     * Guard: cannot deactivate a plan that companies are using.
     */
    public function execute(int $id): Plan
    {
        $plan = Plan::findOrFail($id);

        if ($plan->is_active) {
            $count = Company::where('plan_key', $plan->key)->count();

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'plan' => ["Cannot deactivate: {$count} companies are using this plan."],
                ]);
            }
        }

        $plan->update(['is_active' => ! $plan->is_active]);

        PlanRegistry::clearCache();

        return $plan;
    }
}
