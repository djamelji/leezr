<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Dashboard\PeriodParser;

class BillingRefundRatioWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.refund_ratio';
    }

    public function module(): string
    {
        return 'platform.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 4,
            'default_h' => 4,
            'min_w' => 3,
            'max_w' => 8,
            'min_h' => 2,
            'max_h' => 6,
        ];
    }

    public function category(): string
    {
        return 'billing';
    }

    public function component(): string
    {
        return 'BillingRefundRatio';
    }

    public function tags(): array
    {
        return ['refund', 'ratio', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.refundRatio';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.refundRatioDesc';
    }

    public function audience(): string
    {
        return 'platform';
    }

    public function scope(): string
    {
        return 'both';
    }

    public function permissions(): array
    {
        return ['view_billing'];
    }

    public function capabilities(): array
    {
        return [];
    }

    public function defaultConfig(): array
    {
        return ['period' => '30d'];
    }

    public function datasetKey(): ?string
    {
        return 'billing.kpis';
    }

    public function resolve(array $context): array
    {
        $scope = $context['scope'] ?? 'company';
        $period = $context['period'] ?? '30d';
        $from = PeriodParser::parse($period);
        $to = now();

        if ($scope === 'global') {
            $currency = PlatformBillingWidgetsReadService::currencyGlobal();
            $revenue = PlatformBillingWidgetsReadService::revenueTotalsGlobal($from, $to)['revenue'];
            $refunds = PlatformBillingWidgetsReadService::refundTotalsGlobal($from, $to)['refunds'];
        } else {
            $companyId = (int) $context['company_id'];
            $currency = PlatformBillingWidgetsReadService::currencyForCompany($companyId);
            $revenue = PlatformBillingWidgetsReadService::revenueTotals($companyId, $from, $to)['revenue'];
            $refunds = PlatformBillingWidgetsReadService::refundTotals($companyId, $from, $to)['refunds'];
        }

        $ratio = $revenue > 0 ? round(($refunds / $revenue) * 100, 2) : 0.0;

        return [
            'key' => $this->key(),
            'scope' => $scope,
            'currency' => $currency,
            'period' => $period,
            'revenue' => $revenue,
            'refunds' => $refunds,
            'ratio' => $ratio,
        ];
    }

    public function transform(array $dataset, array $context): array
    {
        $revenue = $dataset['revenue'];
        $refunds = $dataset['refunds'];
        $ratio = $revenue > 0 ? round(($refunds / $revenue) * 100, 2) : 0.0;

        return [
            'key' => $this->key(),
            'scope' => $context['scope'] ?? 'global',
            'currency' => $dataset['currency'],
            'period' => $context['period'] ?? '30d',
            'revenue' => $revenue,
            'refunds' => $refunds,
            'ratio' => $ratio,
        ];
    }
}
