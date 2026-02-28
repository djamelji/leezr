<?php

namespace App\Modules\Dashboard\Contracts;

/**
 * Default implementations for V2 WidgetManifest methods.
 */
trait WidgetLayoutDefaults
{
    public function layout(): array
    {
        return [
            'default_w' => 4,
            'default_h' => 4,
            'min_w' => 3,
            'max_w' => 12,
            'min_h' => 2,
            'max_h' => 8,
        ];
    }

    public function category(): string
    {
        return $this->module();
    }

    public function tags(): array
    {
        return [];
    }

    public function component(): string
    {
        return $this->key();
    }

    public function audience(): string
    {
        return 'platform';
    }

    public function datasetKey(): ?string
    {
        return null;
    }

    public function transform(array $dataset, array $context): array
    {
        return $this->resolve($context);
    }
}
