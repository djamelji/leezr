<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Billing\BillingMetricsCalculationService;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-746 (P2) — panneau composite « Pilotage commercial » (platform, server-resolved).
 * MRR / ARR + évolution MRR (12 mois) + abonnements actifs / essais + churn + conversion.
 * Source réelle : BillingMetricsCalculationService (autorité MRR). Montants en cents.
 */
class PlatformCommercialOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.commercial_overview'; }
    public function module(): string { return 'platform.billing'; }
    public function layout(): array { return ['default_w' => 8, 'default_h' => 4, 'min_w' => 4, 'max_w' => 12, 'min_h' => 3, 'max_h' => 5]; }
    public function category(): string { return 'commercial'; }
    public function component(): string { return 'PlatformCommercialOverview'; }
    public function tags(): array { return ['billing', 'commercial', 'overview']; }
    public function labelKey(): string { return 'platform.widgets.commercialOverview'; }
    public function descriptionKey(): string { return 'platform.widgets.commercialOverviewDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_billing']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $m = app(BillingMetricsCalculationService::class)->calculate();

            return [
                'currency' => $m['currency'] ?? 'EUR',
                'mrr' => (int) ($m['mrr'] ?? 0),
                'arr' => (int) ($m['arr'] ?? 0),
                'mrr_previous' => (int) ($m['mrr_previous_month'] ?? 0),
                'active_subscriptions' => (int) ($m['active_subscriptions'] ?? 0),
                'trialing_subscriptions' => (int) ($m['trialing_subscriptions'] ?? 0),
                'churn_rate' => (float) ($m['churn_rate'] ?? 0),
                'trial_conversion_rate' => (float) ($m['trial_conversion_rate'] ?? 0),
                'mrr_history' => [
                    'labels' => $m['mrr_history']['labels'] ?? [],
                    'series' => $m['mrr_history']['series'] ?? [],
                ],
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
