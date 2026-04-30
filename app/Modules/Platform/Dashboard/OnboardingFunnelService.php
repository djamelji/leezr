<?php

namespace App\Modules\Platform\Dashboard;

use App\Core\Registration\RegistrationFunnelEvent;
use Illuminate\Support\Carbon;

class OnboardingFunnelService
{
    public function analytics(int $days = 30): array
    {
        $from = Carbon::now()->subDays($days);
        $to = Carbon::now();

        $steps = ['started', 'company_info', 'admin_user', 'plan_selected', 'payment_info', 'completed'];
        $counts = [];

        foreach ($steps as $step) {
            $counts[$step] = RegistrationFunnelEvent::step($step)->inPeriod($from, $to)->count();
        }

        $started = max($counts['started'], 1);
        $conversionRates = [];
        foreach ($steps as $step) {
            $conversionRates[$step] = round($counts[$step] / $started * 100, 1);
        }

        // Drop-off analysis: sessions that started but never completed
        $completedSessions = RegistrationFunnelEvent::step('completed')
            ->inPeriod($from, $to)
            ->pluck('session_id');

        $abandonedCount = RegistrationFunnelEvent::step('started')
            ->inPeriod($from, $to)
            ->whereNotIn('session_id', $completedSessions)
            ->count();

        // Last step reached by abandoned sessions
        $abandonedAtStep = [];
        if ($abandonedCount > 0) {
            $abandonedSessions = RegistrationFunnelEvent::step('started')
                ->inPeriod($from, $to)
                ->whereNotIn('session_id', $completedSessions)
                ->pluck('session_id');

            $lastSteps = RegistrationFunnelEvent::whereIn('session_id', $abandonedSessions)
                ->inPeriod($from, $to)
                ->selectRaw("session_id, MAX(CASE step WHEN 'payment_info' THEN 5 WHEN 'plan_selected' THEN 4 WHEN 'admin_user' THEN 3 WHEN 'company_info' THEN 2 WHEN 'started' THEN 1 ELSE 0 END) as last_step_num")
                ->groupBy('session_id')
                ->pluck('last_step_num');

            $stepNames = [1 => 'started', 2 => 'company_info', 3 => 'admin_user', 4 => 'plan_selected', 5 => 'payment_info'];
            foreach ($lastSteps as $num) {
                $name = $stepNames[$num] ?? 'unknown';
                $abandonedAtStep[$name] = ($abandonedAtStep[$name] ?? 0) + 1;
            }
        }

        // Daily trend (last 7 days)
        $dailyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i);
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            $dailyTrend[] = [
                'date' => $day->toDateString(),
                'started' => RegistrationFunnelEvent::step('started')->inPeriod($dayStart, $dayEnd)->count(),
                'completed' => RegistrationFunnelEvent::step('completed')->inPeriod($dayStart, $dayEnd)->count(),
            ];
        }

        return [
            'period_days' => $days,
            'steps' => $counts,
            'conversion_rates' => $conversionRates,
            'abandoned' => $abandonedCount,
            'abandoned_at_step' => $abandonedAtStep,
            'daily_trend' => $dailyTrend,
            'overall_conversion' => $conversionRates['completed'] ?? 0,
        ];
    }
}
