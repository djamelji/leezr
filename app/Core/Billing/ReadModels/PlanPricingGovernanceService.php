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

    /**
     * ADR-594 — subscriptions whose contracted price is NOT reliably verified and whose renewal is
     * therefore held for tariff review: provenance provisional/null, or flagged pricing_review_required.
     * Read-only; integer cents; includes the last finalized invoice and the renewal status.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function reviewSubscriptions(): array
    {
        $subs = Subscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])->where('is_current', 1)
            ->where(function ($q) {
                $q->whereNull('plan_price_source')
                    ->orWhere('plan_price_source', 'provisional')
                    ->orWhereJsonContains('metadata->pricing_review_required', true);
            })
            ->with('company.market')->orderBy('id')->get();

        return $subs->map(function (Subscription $s) {
            $plan = Plan::where('key', $s->plan_key)->first();
            $public = $plan ? (int) ($s->interval === 'yearly' ? ($plan->price_yearly ?? 0) : ($plan->price_monthly ?? 0)) : null;
            $lastInvoice = \App\Core\Billing\Invoice::withoutGlobalScopes()
                ->where('subscription_id', $s->id)->whereNotNull('finalized_at')
                ->orderByDesc('finalized_at')->first();
            $meta = $s->metadata ?? [];

            return [
                'subscription_id' => $s->id,
                'company_id' => $s->company_id,
                'company' => $s->company?->name,
                'plan_key' => $s->plan_key,
                'interval' => $s->interval,
                'currency' => $s->company?->market?->currency ?? 'EUR',
                'contracted_cents' => $s->plan_price_cents,
                'public_cents' => $public,
                'price_source' => $s->plan_price_source,
                'review_reason' => $meta['pricing_review_reason'] ?? ($s->plan_price_source === 'provisional' ? 'unverified_snapshot' : 'missing_snapshot'),
                'pricing_review_required' => (bool) ($meta['pricing_review_required'] ?? false),
                'last_invoice' => $lastInvoice ? ['id' => $lastInvoice->id, 'number' => $lastInvoice->number, 'subtotal_cents' => (int) $lastInvoice->subtotal, 'finalized_at' => optional($lastInvoice->finalized_at)->toDateString()] : null,
                'next_due' => optional($s->current_period_end)->toDateString(),
                'reliable' => $s->hasReliableContractedPrice(),
            ];
        })->all();
    }

    /**
     * Selectable subscriptions for a plan (migration wizard) — id, company, contracted + public price,
     * currency, and whether a migration is already scheduled. Read-only, integer cents.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function subscriptionsForPlan(string $planKey, string $interval = 'monthly'): array
    {
        $plan = Plan::where('key', $planKey)->first();
        $public = $plan ? (int) ($interval === 'yearly' ? ($plan->price_yearly ?? 0) : ($plan->price_monthly ?? 0)) : null;

        return Subscription::where('plan_key', $planKey)->where('interval', $interval)
            ->whereIn('status', ['active', 'trialing', 'past_due'])->where('is_current', 1)
            ->with('company.market')->orderBy('id')->get()
            ->map(fn ($s) => [
                'subscription_id' => $s->id,
                'company_id' => $s->company_id,
                'company' => $s->company?->name,
                'currency' => $s->company?->market?->currency ?? 'EUR',
                'contracted_cents' => (int) ($s->contractedPlanPriceCents() ?? 0),
                'public_cents' => $public,
                'scheduled_migration' => $s->metadata['scheduled_price_migration'] ?? null,
            ])->all();
    }
}
