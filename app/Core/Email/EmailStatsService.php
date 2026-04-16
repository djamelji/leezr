<?php

namespace App\Core\Email;

use Illuminate\Support\Facades\DB;

class EmailStatsService
{
    public static function dashboard(): array
    {
        $sent24h = EmailLog::sent()->where('created_at', '>=', now()->subDay())->count();
        $failed24h = EmailLog::failed()->where('created_at', '>=', now()->subDay())->count();
        $total24h = $sent24h + $failed24h;
        $successRate = $total24h > 0 ? round(($sent24h / $total24h) * 100, 1) : 100;

        // By template (last 7 days)
        $byTemplate = EmailLog::where('created_at', '>=', now()->subDays(7))
            ->select('template_key', 'status', DB::raw('COUNT(*) as count'))
            ->groupBy('template_key', 'status')
            ->get()
            ->groupBy('template_key')
            ->map(fn ($group) => [
                'sent' => $group->where('status', 'sent')->sum('count'),
                'failed' => $group->where('status', 'failed')->sum('count'),
                'queued' => $group->where('status', 'queued')->sum('count'),
            ]);

        // Daily volume (last 7 days)
        $byDay = EmailLog::where('created_at', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->pluck('count', 'date');

        return [
            'sent_24h' => $sent24h,
            'failed_24h' => $failed24h,
            'success_rate' => $successRate,
            'by_template' => $byTemplate,
            'by_day_7d' => $byDay,
        ];
    }
}
