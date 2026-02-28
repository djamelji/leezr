<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingTopFailureReasonsWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.top_failure_reasons';
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
        return 'BillingTopFailureReasons';
    }

    public function tags(): array
    {
        return ['failure', 'reasons', 'risk', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.topFailureReasons';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.topFailureReasonsDesc';
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
            'reasons' => $dataset['top_failure_reasons'] ?? [],
        ];
    }
}
