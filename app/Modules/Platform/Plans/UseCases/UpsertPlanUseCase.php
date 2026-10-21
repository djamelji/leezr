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
            $before = ['price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly];
            $plan->update($validated);

            // ADR-588: trace every PUBLIC price change (who/when/old/new). Existing subscribers keep
            // their contracted snapshot (ADR-587) — this change affects only NEW subscriptions.
            if ($before['price_monthly'] !== $plan->price_monthly || $before['price_yearly'] !== $plan->price_yearly) {
                app(\App\Core\Audit\AuditLogger::class)->logPlatform(
                    'plan.public_price_changed',
                    'plan',
                    (string) $plan->id,
                    [
                        'severity' => 'warning',
                        'diffBefore' => $before,
                        'diffAfter' => ['price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly],
                        'metadata' => ['plan_key' => $plan->key, 'note' => 'Affects new subscriptions only; existing subscribers keep their contracted price.'],
                    ],
                );
            }
        }

        PlanRegistry::clearCache();

        return $plan;
    }
}
