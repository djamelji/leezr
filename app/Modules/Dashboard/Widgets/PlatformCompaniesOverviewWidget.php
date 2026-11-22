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
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 3, 'max_w' => 12, 'min_h' => 2, 'max_h' => 5]; }
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

            // ADR-855 (Leezr 5.0 cockpit) — 30-day creation trend and the commercial status mix of the CURRENT
            // subscriptions (no amount : status only, same permission view_companies). Additive keys only.
            $from = Carbon::now()->subDays(29)->startOfDay();
            $perDay = Company::where('created_at', '>=', $from)->get(['created_at'])
                ->groupBy(fn ($c) => $c->created_at->toDateString())->map->count();
            $trend30 = [];
            for ($i = 29; $i >= 0; $i--) {
                $trend30[] = (int) ($perDay[Carbon::now()->subDays($i)->toDateString()] ?? 0);
            }
            $mix = \Illuminate\Support\Facades\DB::table('subscriptions')->where('is_current', 1)
                ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

            return [
                'trend_30d' => $trend30,
                'subscription_mix' => [
                    'active' => (int) ($mix['active'] ?? 0),
                    'trialing' => (int) ($mix['trialing'] ?? 0),
                    'past_due' => (int) ($mix['past_due'] ?? 0),
                    'other' => (int) $mix->except(['active', 'trialing', 'past_due'])->sum(),
                ],
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
