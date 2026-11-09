<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Support\SupportTicket;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/** ADR-746 (P2) — « Support » (platform, server). Source : SupportTicket (status/assignation). */
class PlatformSupportOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.support_overview'; }
    public function module(): string { return 'platform.support'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 3, 'min_w' => 3, 'max_w' => 12, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'support'; }
    public function component(): string { return 'PlatformSupportOverview'; }
    public function tags(): array { return ['support', 'tickets']; }
    public function labelKey(): string { return 'platform.widgets.supportOverview'; }
    public function descriptionKey(): string { return 'platform.widgets.supportOverviewDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'both'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['manage_support']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $open = SupportTicket::whereIn('status', ['open', 'acknowledged', 'in_progress'])->count();

            return [
                'open' => $open,
                'unassigned' => SupportTicket::whereNull('assigned_to_platform_user_id')
                    ->whereIn('status', ['open', 'acknowledged', 'in_progress'])->count(),
                'resolved' => SupportTicket::whereIn('status', ['resolved', 'closed'])->count(),
                'total' => SupportTicket::count(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
