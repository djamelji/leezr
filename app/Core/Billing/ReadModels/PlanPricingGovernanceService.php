<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;

/**
 * Pricing governance (ADR-588, read-only). Explains the impact of changing a plan's PUBLIC price:
 * existing subscribers keep their contracted snapshot (ADR-587); only new subscriptions use the new
 * public price. Numbers come from real data, grouped per currency (never mixed), in integer cents.
 */
class PlanPricingGovernanceService
{
    /**
     * @return array{
     *   plan_key:string, interval:string, current_public_cents:?int, proposed_cents:?int,
     *   affected_subscriptions:int, by_currency: array<int, array<string,mixed>>
     * }
     */
    public static function impact(string $planKey, string $interval = 'monthly', ?int $proposedCents = null): array
    {
        $plan = Plan::where('key', $planKey)->first();
        $currentPublic = $plan ? (int) ($interval === 'yearly' ? ($plan->price_yearly ?? 0) : ($plan->price_monthly ?? 0)) : null;

        $subs = Subscription::where('plan_key', $planKey)
            ->where('interval', $interval)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->where('is_current', 1)
            ->with('company.market')
            ->get();

        $byCur = [];
        foreach ($subs as $s) {
            $cur = $s->company?->market?->currency ?? 'EUR';
            $contracted = (int) ($s->contractedPlanPriceCents() ?? 0);
            $byCur[$cur] ??= ['currency' => $cur, 'count' => 0, 'sum_contracted_cents' => 0, 'min_contracted_cents' => null, 'max_contracted_cents' => null];
            $byCur[$cur]['count']++;
            $byCur[$cur]['sum_contracted_cents'] += $contracted;
            $byCur[$cur]['min_contracted_cents'] = $byCur[$cur]['min_contracted_cents'] === null ? $contracted : min($byCur[$cur]['min_contracted_cents'], $contracted);
            $byCur[$cur]['max_contracted_cents'] = max($byCur[$cur]['max_contracted_cents'] ?? 0, $contracted);
        }

        return [
            'plan_key' => $planKey,
            'interval' => $interval,
            'current_public_cents' => $currentPublic,
            'proposed_cents' => $proposedCents,
            'affected_subscriptions' => $subs->count(),
            'by_currency' => array_values($byCur),
            'note' => 'Existing subscribers keep their contracted price; the new public price applies to new subscriptions only.',
        ];
    }
}
