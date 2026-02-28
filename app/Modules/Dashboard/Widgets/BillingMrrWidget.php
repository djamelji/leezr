<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingMrrWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.mrr';
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
        return 'BillingMrr';
    }

    public function tags(): array
    {
        return ['mrr', 'kpi', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.mrr';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.mrrDesc';
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
        return $this->transform([], $context);
    }

    public function transform(array $dataset, array $context): array
    {
        $mrr = $dataset['mrr'] ?? null;

        return [
            'key' => $this->key(),
            'scope' => $context['scope'] ?? 'global',
            'currency' => $dataset['currency'] ?? null,
            'data' => $mrr,
            'meta' => $mrr === null ? ['not_supported' => true] : [],
        ];
    }
}
