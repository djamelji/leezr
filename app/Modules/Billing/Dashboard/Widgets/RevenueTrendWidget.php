<?php

namespace App\Modules\Billing\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Billing\Dashboard\BillingDashboardWidget;

class RevenueTrendWidget implements BillingDashboardWidget
{
    public function key(): string
    {
        return 'revenue_trend';
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.revenueTrend';
    }

    public function defaultPeriod(): string
    {
        return '30d';
    }

    public function resolve(int $companyId, string $period): array
    {
        $from = self::parsePeriod($period);
        $to = now();

        $currency = PlatformBillingWidgetsReadService::currencyForCompany($companyId);
        $chart = PlatformBillingWidgetsReadService::revenueTrend($companyId, $from, $to);

        return [
            'key' => $this->key(),
            'currency' => $currency,
            'period' => $period,
            'chart' => $chart,
        ];
    }

    private static function parsePeriod(string $period): \Carbon\Carbon
    {
        return match ($period) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };
    }
}
