<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingFailedPayments7dWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.failed_payments_7d';
    }

    public function module(): string
    {
        return 'platform.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 3,
            'default_h' => 2,
            'min_w' => 3,
            'max_w' => 6,
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
        return 'BillingFailedPayments7d';
    }

    public function tags(): array
    {
        return ['failed', 'payments', 'risk', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.failedPayments7d';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.failedPayments7dDesc';
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
        return 'billing.risk';
    }

    public function resolve(array $context): array
    {
        return $this->transform([], $context);
    }

    public function transform(array $dataset, array $context): array
    {
        return [
            'key' => $this->key(),
            'scope' => $context['scope'] ?? 'global',
            'count' => $dataset['failed_payments_7d'] ?? 0,
        ];
    }
}
