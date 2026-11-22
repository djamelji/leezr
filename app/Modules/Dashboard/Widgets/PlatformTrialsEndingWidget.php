<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-855 (Leezr 5.0 cockpit) — « Essais qui se terminent » : current subscriptions in trial, soonest end first.
 * Status and dates only (no amount) → `view_companies`, like the companies list. Read-only.
 */
class PlatformTrialsEndingWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'platform.trials_ending'; }
    public function module(): string { return 'platform.companies'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 4, 'min_w' => 3, 'max_w' => 8, 'min_h' => 2, 'max_h' => 8]; }
    public function category(): string { return 'companies'; }
    public function component(): string { return 'PlatformTrialsEnding'; }
    public function tags(): array { return ['trial', 'subscriptions']; }
    public function labelKey(): string { return 'platform.widgets.trialsEnding'; }
    public function descriptionKey(): string { return 'platform.widgets.trialsEndingDesc'; }
    public function audience(): string { return 'platform'; }
    public function scope(): string { return 'global'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['view_companies']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return null; }

    public function resolve(array $context): array
    {
        try {
            $now = Carbon::now();
            $rows = DB::table('subscriptions')
                ->join('companies', 'companies.id', '=', 'subscriptions.company_id')
                ->where('subscriptions.is_current', 1)->where('subscriptions.status', 'trialing')
                ->whereNotNull('subscriptions.trial_ends_at')
                ->orderBy('subscriptions.trial_ends_at')
                ->limit(12)
                ->get(['companies.id', 'companies.name', 'companies.status as company_status', 'subscriptions.plan_key', 'subscriptions.trial_ends_at']);

            return [
                'total' => DB::table('subscriptions')->where('is_current', 1)->where('status', 'trialing')->count(),
                'ending_7d' => DB::table('subscriptions')->where('is_current', 1)->where('status', 'trialing')
                    ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays(7)])->count(),
                'items' => $rows->map(fn ($r) => [
                    'company_id' => $r->id,
                    'name' => $r->name,
                    'plan_key' => $r->plan_key,
                    'trial_ends_at' => Carbon::parse($r->trial_ends_at)->toIso8601String(),
                    'days_left' => (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($r->trial_ends_at)->startOfDay(), false),
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
