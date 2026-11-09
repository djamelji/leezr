<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Security\SecurityAlert;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/** ADR-746 (P2) — « Sécurité » (platform, server). Source : SecurityAlert (status/severity). */
class PlatformSecurityOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.security_overview'; }
    public function module(): string { return 'platform.security'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 3, 'min_w' => 3, 'max_w' => 12, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'security'; }
    public function component(): string { return 'PlatformSecurityOverview'; }
    public function tags(): array { return ['security', 'alerts']; }
    public function labelKey(): string { return 'platform.widgets.securityOverview'; }
    public function descriptionKey(): string { return 'platform.widgets.securityOverviewDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['security.alerts.view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $openQ = fn () => SecurityAlert::where('status', 'open');

            return [
                'open' => $openQ()->count(),
                'critical' => (clone $openQ())->where('severity', 'critical')->count(),
                'high' => (clone $openQ())->where('severity', 'high')->count(),
                'medium' => (clone $openQ())->where('severity', 'medium')->count(),
                'total' => SecurityAlert::count(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
