<?php

namespace App\Core\Usage;

use App\Core\Models\Company;
use Illuminate\Support\Carbon;

class UsageAnalyticsService
{
    /**
     * Platform-wide usage overview for the given period.
     */
    public function platformOverview(int $days = 30): array
    {
        $from = Carbon::today()->subDays($days);
        $to = Carbon::today();

        $snapshots = UsageSnapshot::inPeriod($from, $to)->get();

        // Aggregated totals
        $totals = [
            'ai_requests' => $snapshots->sum('ai_requests'),
            'ai_tokens' => $snapshots->sum('ai_tokens'),
            'emails_sent' => $snapshots->sum('emails_sent'),
            'api_requests' => $snapshots->sum('api_requests'),
        ];

        // Latest snapshot for each company (seat/storage = current state)
        $latestByCompany = $snapshots
            ->groupBy('company_id')
            ->map(fn ($group) => $group->sortByDesc('date')->first());

        $totals['total_members'] = $latestByCompany->sum('active_members');
        $totals['total_storage_gb'] = round($latestByCompany->sum('storage_bytes') / 1073741824, 2);

        // Top 10 companies by AI usage
        $topAiCompanies = $snapshots
            ->groupBy('company_id')
            ->map(fn ($group) => [
                'company_id' => $group->first()->company_id,
                'ai_requests' => $group->sum('ai_requests'),
                'ai_tokens' => $group->sum('ai_tokens'),
            ])
            ->sortByDesc('ai_requests')
            ->take(10)
            ->values()
            ->toArray();

        // Resolve company names
        $companyIds = collect($topAiCompanies)->pluck('company_id');
        $companies = Company::whereIn('id', $companyIds)->pluck('name', 'id');

        foreach ($topAiCompanies as &$item) {
            $item['company_name'] = $companies[$item['company_id']] ?? '?';
        }

        unset($item);

        // Daily trend
        $dailyTrend = $snapshots
            ->groupBy(fn ($s) => $s->date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'ai_requests' => $group->sum('ai_requests'),
                'emails_sent' => $group->sum('emails_sent'),
                'api_requests' => $group->sum('api_requests'),
            ])
            ->sortKeys()
            ->values()
            ->toArray();

        return [
            'period_days' => $days,
            'totals' => $totals,
            'top_ai_companies' => $topAiCompanies,
            'daily_trend' => $dailyTrend,
        ];
    }

    /**
     * Per-company usage detail for the given period.
     */
    public function companyDetail(int $companyId, int $days = 30): array
    {
        $from = Carbon::today()->subDays($days);
        $to = Carbon::today();

        $snapshots = UsageSnapshot::forCompany($companyId)
            ->inPeriod($from, $to)
            ->orderBy('date')
            ->get();

        $totals = [
            'ai_requests' => $snapshots->sum('ai_requests'),
            'ai_tokens' => $snapshots->sum('ai_tokens'),
            'emails_sent' => $snapshots->sum('emails_sent'),
            'api_requests' => $snapshots->sum('api_requests'),
        ];

        $latest = $snapshots->last();
        $totals['active_members'] = $latest?->active_members ?? 0;
        $totals['storage_gb'] = round(($latest?->storage_bytes ?? 0) / 1073741824, 2);

        return [
            'period_days' => $days,
            'totals' => $totals,
            'daily' => $snapshots->map(fn ($s) => [
                'date' => $s->date->toDateString(),
                'ai_requests' => $s->ai_requests,
                'emails_sent' => $s->emails_sent,
                'active_members' => $s->active_members,
                'storage_bytes' => $s->storage_bytes,
            ])->toArray(),
        ];
    }
}
