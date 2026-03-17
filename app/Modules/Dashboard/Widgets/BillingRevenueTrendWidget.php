<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Dashboard\PeriodParser;

class BillingRevenueTrendWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.revenue_trend';
    }

    public function module(): string
    {
        return 'platform.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 8,
            'default_h' => 4,
            'min_w' => 3,
            'max_w' => 12,
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
        return 'BillingRevenueTrend';
    }

    public function tags(): array
    {
        return ['revenue', 'chart', 'trend'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.revenueTrend';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.revenueTrendDesc';
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
        return 'billing.timeseries';
    }

    public function resolve(array $context): array
    {
        $scope = $context['scope'] ?? 'company';
        $period = $context['period'] ?? '30d';
        $from = PeriodParser::parse($period);
        $to = now();

        if ($scope === 'global') {
            $currency = PlatformBillingWidgetsReadService::currencyGlobal();
            $chart = PlatformBillingWidgetsReadService::revenueTrendGlobal($from, $to);
        } else {
            $companyId = (int) $context['company_id'];
            $currency = PlatformBillingWidgetsReadService::currencyForCompany($companyId);
            $chart = PlatformBillingWidgetsReadService::revenueTrend($companyId, $from, $to);
        }

        return [
            'key' => $this->key(),
            'scope' => $scope,
            'currency' => $currency,
            'period' => $period,
            'chart' => $chart,
        ];
    }

    public function transform(array $dataset, array $context): array
    {
        return [
            'key' => $this->key(),
            'scope' => $context['scope'] ?? 'global',
            'currency' => $dataset['currency'],
            'period' => $context['period'] ?? '30d',
            'chart' => $dataset['revenue_trend'],
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
