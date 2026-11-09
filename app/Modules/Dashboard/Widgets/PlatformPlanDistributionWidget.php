<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Company;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Facades\DB;

/** ADR-746 (P2) — « Répartition des abonnements par plan » (platform, server). Source : Company.plan_key. */
class PlatformPlanDistributionWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.plan_distribution'; }
    public function module(): string { return 'platform.billing'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 4, 'min_w' => 3, 'max_w' => 12, 'min_h' => 3, 'max_h' => 5]; }
    public function category(): string { return 'commercial'; }
    public function component(): string { return 'PlatformPlanDistribution'; }
    public function tags(): array { return ['billing', 'plans', 'distribution']; }
    public function labelKey(): string { return 'platform.widgets.planDistribution'; }
    public function descriptionKey(): string { return 'platform.widgets.planDistributionDesc'; }
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
            $rows = Company::whereNotNull('plan_key')
                ->select('plan_key', DB::raw('COUNT(*) as c'))
                ->groupBy('plan_key')->pluck('c', 'plan_key');

            $dist = [];
            foreach ($rows as $plan => $c) {
                $dist[] = ['plan' => (string) $plan, 'count' => (int) $c];
            }

            return ['total' => (int) array_sum(array_column($dist, 'count')), 'distribution' => $dist];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
