<?php

namespace App\Modules\Dashboard\Contracts;

interface WidgetManifest
{
    public function key(): string;

    public function module(): string;

    public function labelKey(): string;

    public function descriptionKey(): string;

    /**
     * 'global' | 'company' | 'both'
     */
    public function scope(): string;

    /** @return string[] Required platform permissions */
    public function permissions(): array;

    /** @return string[] Required capabilities (stub for now) */
    public function capabilities(): array;

    public function defaultConfig(): array;

    /**
     * Layout constraints for grid placement.
     *
     * @return array{default_w: int, default_h: int, min_w: int, max_w: int, min_h: int, max_h: int}
     */
    public function layout(): array;

    /** UI grouping category (e.g. 'billing', 'operations') */
    public function category(): string;

    /** @return string[] Search/filter tags */
    public function tags(): array;

    /** Frontend component key for widgetComponentMap lookup */
    public function component(): string;

    /** Target audience: 'platform' | 'company' | 'both' */
    public function audience(): string;

    /**
     * Resolve widget data.
     *
     * @param array{scope: string, company_id: ?int, period: ?string} $context
     */
    public function resolve(array $context): array;
}
