<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingArOutstandingWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.ar_outstanding';
    }

    public function module(): string
    {
        return 'platform.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 4,
            'default_h' => 2,
            'min_w' => 3,
            'max_w' => 8,
            'min_h' => 2,
            'max_h' => 4,
        ];
    }

    public function category(): string
    {
        return 'billing';
    }

    public function component(): string
    {
        return 'BillingArOutstanding';
    }

    public function tags(): array
    {
        return ['accounts-receivable', 'outstanding', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.arOutstanding';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.arOutstandingDesc';
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
        return [];
    }

    public function datasetKey(): ?string
    {
        return 'billing.kpis';
    }

    public function resolve(array $context): array
    {
        $scope = $context['scope'] ?? 'company';

        if ($scope === 'global') {
            $currency = PlatformBillingWidgetsReadService::currencyGlobal();
            $outstanding = PlatformBillingWidgetsReadService::arOutstandingGlobal()['outstanding'];
        } else {
            $companyId = (int) $context['company_id'];
            $currency = PlatformBillingWidgetsReadService::currencyForCompany($companyId);
            $outstanding = PlatformBillingWidgetsReadService::arOutstanding($companyId)['outstanding'];
        }

        return [
            'key' => $this->key(),
            'scope' => $scope,
            'currency' => $currency,
            'outstanding' => $outstanding,
        ];
    }

    public function transform(array $dataset, array $context): array
    {
        return [
            'key' => $this->key(),
            'scope' => $context['scope'] ?? 'global',
            'currency' => $dataset['currency'],
            'outstanding' => $dataset['outstanding'],
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
