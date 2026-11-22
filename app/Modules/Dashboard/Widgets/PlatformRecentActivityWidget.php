<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Audit\PlatformAuditLog;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-855 (Leezr 5.0 cockpit) — « Activité récente » : the Platform audit journal, without the login noise.
 * Same source and permission as the Activity page (`view_audit_logs`). Read-only ; no IP / user agent exposed.
 */
class PlatformRecentActivityWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.recent_activity'; }
    public function module(): string { return 'platform.activity'; }
    public function layout(): array { return ['default_w' => 8, 'default_h' => 4, 'min_w' => 3, 'max_w' => 12, 'min_h' => 3, 'max_h' => 8]; }
    public function category(): string { return 'activity'; }
    public function component(): string { return 'PlatformRecentActivity'; }
    public function tags(): array { return ['audit', 'activity']; }
    public function labelKey(): string { return 'platform.widgets.recentActivity'; }
    public function descriptionKey(): string { return 'platform.widgets.recentActivityDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'global'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_audit_logs']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $logs = PlatformAuditLog::query()
                ->where('action', 'not like', 'platform_auth.%')
                ->where('action', 'not like', 'auth.%')
                ->latest('id')->limit(10)
                ->get(['id', 'action', 'actor_id', 'actor_type', 'target_type', 'target_id', 'severity', 'created_at']);
            $actors = \App\Platform\Models\PlatformUser::whereIn('id', $logs->where('actor_type', '!=', 'system')->pluck('actor_id')->filter()->unique())
                ->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $companies = \App\Core\Models\Company::whereIn('id', $logs->where('target_type', 'company')->pluck('target_id')->filter()->unique())
                ->pluck('name', 'id');

            return [
                'items' => $logs->map(fn ($l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'severity' => $l->severity ?: 'info',
                    'actor' => $actors->get($l->actor_id) ? trim($actors->get($l->actor_id)->first_name.' '.$actors->get($l->actor_id)->last_name) : null,
                    'target_type' => $l->target_type,
                    'target_id' => $l->target_id,
                    'company_name' => $l->target_type === 'company' ? ($companies[$l->target_id] ?? null) : null,
                    'at' => $l->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
