<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingLastRefundsWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.last_refunds';
    }

    public function module(): string
    {
        return 'platform.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 6,
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
        return 'BillingLastRefunds';
    }

    public function tags(): array
    {
        return ['refunds', 'activity', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.lastRefunds';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.lastRefundsDesc';
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
        return 'billing.activity';
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
            'items' => $dataset['last_refunds'] ?? [],
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
