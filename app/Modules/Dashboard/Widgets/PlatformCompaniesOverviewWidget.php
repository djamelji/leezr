<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Company;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;

/** ADR-746 (P2) — « Companies & inscriptions » (platform, server). Source : Company (status/created_at). */
class PlatformCompaniesOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.companies_overview'; }
    public function module(): string { return 'platform.companies'; }
    public function layout(): array { return ['default_w' => 6, 'default_h' => 3, 'min_w' => 4, 'max_w' => 12, 'min_h' => 3, 'max_h' => 4]; }
    public function category(): string { return 'operations'; }
    public function component(): string { return 'PlatformCompaniesOverview'; }
    public function tags(): array { return ['companies', 'operations', 'overview']; }
    public function labelKey(): string { return 'platform.widgets.companiesOverview'; }
    public function descriptionKey(): string { return 'platform.widgets.companiesOverviewDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_companies']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            // Tendance des inscriptions sur 7 jours (comptage créations par jour).
            $labels = []; $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i);
                $labels[] = ucfirst($d->locale('fr')->isoFormat('dd'));
                $trend[] = Company::whereDate('created_at', $d->toDateString())->count();
            }

            return [
                'active' => Company::where('status', 'active')->count(),
                'suspended' => Company::where('status', 'suspended')->count(),
                'total' => Company::count(),
                'new_30d' => Company::where('created_at', '>=', Carbon::now()->subDays(30))->count(),
                'new_7d' => array_sum($trend),
                'trend' => ['labels' => $labels, 'values' => $trend],
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
