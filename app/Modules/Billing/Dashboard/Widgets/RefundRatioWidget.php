<?php

namespace App\Modules\Billing\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Billing\Dashboard\BillingDashboardWidget;

class RefundRatioWidget implements BillingDashboardWidget
{
    public function key(): string
    {
        return 'refund_ratio';
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.refundRatio';
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
        $revenue = PlatformBillingWidgetsReadService::revenueTotals($companyId, $from, $to)['revenue'];
        $refunds = PlatformBillingWidgetsReadService::refundTotals($companyId, $from, $to)['refunds'];

        $ratio = $revenue > 0 ? round(($refunds / $revenue) * 100, 2) : 0.0;

        return [
            'key' => $this->key(),
            'currency' => $currency,
            'period' => $period,
            'revenue' => $revenue,
            'refunds' => $refunds,
            'ratio' => $ratio,
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
