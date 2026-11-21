<?php

namespace App\Modules\Platform\Dashboard;

use App\Core\Registration\RegistrationFunnelEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public registration funnel (Platform dashboard, view_usage_monitoring). ADR-849 (Leezr 4.2 M10) — measured on real
 * events only, per JOURNEY (random id from the browser ; historical rows : their session id) :
 *  - cohort = journeys STARTED in the period (explicit `from` / `to`) ;
 *  - steps = journeys that passed each step of the real screen (one row per journey and step) ;
 *  - completed = the server wrote `completed` (public registration only — Platform creations never write here) ;
 *  - activated = completed and the company has a current active or trialing subscription (not awaiting payment) ;
 *  - first login = the registered owner signed in (`auth.login` audit) after the registration ;
 *  - abandoned = not completed AND no event for the inactivity window (config registration_funnel.abandon_after_hours) ;
 *    in progress otherwise. Never inferred from a click, a page close or a missing datum.
 */
class OnboardingFunnelService
{
    public function analytics(int $days = 30, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $from = $now->copy()->subDays($days);
        $hours = (int) config('registration_funnel.abandon_after_hours');
        $journeys = $this->journeys($from, $now);

        $steps = [];
        foreach ([...RegistrationFunnelEvent::CLIENT_STEPS, RegistrationFunnelEvent::COMPLETED] as $step) {
            $steps[$step] = $journeys->filter(fn ($j) => in_array($step, $j['steps'], true))->count();
        }
        $started = max($steps['started'], 1);
        $completed = $journeys->filter(fn ($j) => $j['completed']);
        $open = $journeys->reject(fn ($j) => $j['completed']);
        $abandoned = $open->filter(fn ($j) => $j['last_event_at']->lte($now->copy()->subHours($hours)));
        $companyIds = $completed->pluck('company_id')->filter()->values()->all();
        $activated = $this->activatedCompanies($companyIds);
        $firstLogin = $this->companiesWithOwnerLogin($completed);

        $abandonedAtStep = [];
        foreach ($abandoned as $j) {
            $last = collect(RegistrationFunnelEvent::CLIENT_STEPS)->last(fn ($s) => in_array($s, $j['steps'], true)) ?? 'started';
            $abandonedAtStep[$last] = ($abandonedAtStep[$last] ?? 0) + 1;
        }

        $dailyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->toDateString();
            $dailyTrend[] = [
                'date' => $day,
                'started' => $journeys->filter(fn ($j) => $j['started_at']?->toDateString() === $day)->count(),
                'completed' => $journeys->filter(fn ($j) => $j['completed_at']?->toDateString() === $day)->count(),
            ];
        }

        return [
            'period_days' => $days,
            'period' => ['from' => $from->toIso8601String(), 'to' => $now->toIso8601String()],
            'abandon_after_hours' => $hours,
            'steps' => $steps,
            'conversion_rates' => array_map(fn ($n) => round($n / $started * 100, 1), $steps),
            'completed' => $completed->count(),
            'activated' => count(array_intersect($companyIds, $activated)),
            'first_login' => count($firstLogin),
            'in_progress' => $open->count() - $abandoned->count(),
            'abandoned' => $abandoned->count(),
            'abandoned_at_step' => $abandonedAtStep,
            'daily_trend' => $dailyTrend,
            'overall_conversion' => round($steps[RegistrationFunnelEvent::COMPLETED] / $started * 100, 1),
        ];
    }

    /** Journeys started in [from, to] with every event (whatever its date) : key = funnel_id, else session_id. */
    public function journeys(Carbon $from, Carbon $to)
    {
        $key = DB::raw('COALESCE(funnel_id, session_id) as journey');
        $startedKeys = RegistrationFunnelEvent::query()->where('step', 'started')->whereBetween('created_at', [$from, $to])
            ->get([$key])->pluck('journey')->unique()->values();
        if ($startedKeys->isEmpty()) {
            return collect();
        }

        return RegistrationFunnelEvent::query()
            ->whereIn(DB::raw('COALESCE(funnel_id, session_id)'), $startedKeys)
            ->orderBy('created_at')
            ->get(['funnel_id', 'session_id', 'step', 'company_id', 'created_at'])
            ->groupBy(fn ($e) => $e->funnel_id ?? $e->session_id)
            ->map(function ($events) {
                $done = $events->firstWhere('step', RegistrationFunnelEvent::COMPLETED);

                return [
                    'steps' => $events->pluck('step')->unique()->values()->all(),
                    'started_at' => $events->firstWhere('step', 'started')?->created_at,
                    'last_event_at' => $events->max('created_at'),
                    'completed' => $done !== null,
                    'completed_at' => $done?->created_at,
                    'company_id' => $done?->company_id,
                ];
            })->values();
    }

    /** companies with a current active / trialing subscription (registration done, not awaiting payment) */
    private function activatedCompanies(array $companyIds): array
    {
        return $companyIds ? DB::table('subscriptions')->whereIn('company_id', $companyIds)->where('is_current', 1)
            ->whereIn('status', ['active', 'trialing'])->distinct()->pluck('company_id')->all() : [];
    }

    /** completed journeys whose company owner signed in (auth.login, written by /login only) after registering */
    private function companiesWithOwnerLogin($completed): array
    {
        $out = [];
        foreach ($completed as $j) {
            if (! $j['company_id']) {
                continue;
            }
            $owner = DB::table('memberships')->where('company_id', $j['company_id'])->where('role', 'owner')->value('user_id');
            if ($owner && DB::table('company_audit_logs')->where('company_id', $j['company_id'])->where('action', 'auth.login')
                ->where('actor_id', $owner)->where('created_at', '>=', $j['completed_at'])->exists()) {
                $out[] = $j['company_id'];
            }
        }

        return $out;
    }
}
