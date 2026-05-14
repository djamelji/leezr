<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Markets\MarketRuleSet;
use Carbon\Carbon;

class ContributionRulesReadModel
{
    /**
     * Get all payroll contribution rules for a market at a given date.
     */
    public static function forMarket(string $marketKey, ?Carbon $at = null): array
    {
        $at ??= now();

        return MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderBy('rule_key')
            ->get()
            ->map(fn (MarketRuleSet $rule) => [
                'rule_key' => $rule->rule_key,
                'value' => $rule->value,
                'effective_from' => $rule->effective_from?->format('Y-m-d'),
                'effective_until' => $rule->effective_until?->format('Y-m-d'),
                'source' => $rule->source,
                'reference' => $rule->reference,
            ])
            ->toArray();
    }
}
