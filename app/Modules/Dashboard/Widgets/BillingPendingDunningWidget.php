<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class BillingPendingDunningWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.pending_dunning';
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
        return 'BillingPendingDunning';
    }

    public function tags(): array
    {
        return ['dunning', 'overdue', 'risk', 'billing'];
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.pendingDunning';
    }

    public function descriptionKey(): string
    {
        return 'platformBilling.widgets.pendingDunningDesc';
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
            'count' => $dataset['pending_dunning'] ?? 0,
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
