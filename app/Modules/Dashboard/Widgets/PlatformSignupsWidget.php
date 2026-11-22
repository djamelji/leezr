<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Platform\Dashboard\OnboardingFunnelService;

/**
 * ADR-855 (Leezr 5.0 cockpit) — « Inscriptions » : the measured public registration funnel (ADR-849), 30 days.
 * Same service and same permission as the funnel page (`view_usage_monitoring`) : nothing recomputed here.
 */
class PlatformSignupsWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.signups'; }
    public function module(): string { return 'platform.dashboard'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 3, 'max_w' => 12, 'min_h' => 2, 'max_h' => 6]; }
    public function category(): string { return 'growth'; }
    public function component(): string { return 'PlatformSignups'; }
    public function tags(): array { return ['funnel', 'registration']; }
    public function labelKey(): string { return 'platform.widgets.signups'; }
    public function descriptionKey(): string { return 'platform.widgets.signupsDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'global'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_usage_monitoring']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $a = app(OnboardingFunnelService::class)->analytics(30);
            $prev = app(OnboardingFunnelService::class)->analytics(30, now()->subDays(30));
            $started = (int) ($a['steps']['started'] ?? 0);

            return [
                'period_days' => 30,
                'started' => $started,
                'completed' => (int) $a['completed'],
                'completed_previous' => (int) $prev['completed'],
                'conversion' => $started > 0 ? (float) $a['overall_conversion'] : null,
                'activated' => (int) $a['activated'],
                'abandoned' => (int) $a['abandoned'],
                'in_progress' => (int) $a['in_progress'],
                'steps' => $a['steps'],
                'daily' => array_map(fn ($d) => ['date' => $d['date'], 'started' => $d['started'], 'completed' => $d['completed']], $a['daily_trend']),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
