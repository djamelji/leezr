<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Platform\Dashboard\SystemHealthService;

/** ADR-746 (P2) — « Santé des services » (platform, server). Source : SystemHealthService::check(). */
class PlatformSystemHealthWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.system_health'; }
    public function module(): string { return 'platform.dashboard'; }
    public function layout(): array { return ['default_w' => 6, 'default_h' => 3, 'min_w' => 4, 'max_w' => 12, 'min_h' => 3, 'max_h' => 4]; }
    public function category(): string { return 'operations'; }
    public function component(): string { return 'PlatformSystemHealth'; }
    public function tags(): array { return ['system', 'health', 'operations']; }
    public function labelKey(): string { return 'platform.widgets.systemHealth'; }
    public function descriptionKey(): string { return 'platform.widgets.systemHealthDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_system_health']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $h = app(SystemHealthService::class)->check();
            $sections = [];
            foreach (($h['sections'] ?? []) as $s) {
                $sections[] = [
                    'key' => $s['key'] ?? '',
                    'label' => $s['label'] ?? '',
                    'status' => $s['status'] ?? 'healthy',
                ];
            }

            return ['status' => $h['status'] ?? 'healthy', 'sections' => $sections];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
