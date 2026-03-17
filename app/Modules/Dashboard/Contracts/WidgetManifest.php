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

    /** Data resolution strategy: 'server' (batch-resolved backend) | 'client' (frontend store) */
    public function resolution(): string;

    /**
     * Resolve widget data.
     *
     * @param array{scope: string, company_id: ?int, period: ?string} $context
     */
    public function resolve(array $context): array;

    /**
     * Dataset key for batch resolution. Return null for individual-only resolution.
     * Widgets sharing the same dataset key share a single data load.
     */
    public function datasetKey(): ?string;

    /**
     * Transform a pre-loaded dataset into widget-specific data.
     * Called only when datasetKey() is non-null.
     *
     * @param array $dataset  The pre-loaded dataset
     * @param array $context  The widget request context
     */
    public function transform(array $dataset, array $context): array;

    /**
     * ADR-357: Allowed role archetypes, or null for all.
     *
     * @return string[]|null  e.g. ['management', 'operations_center'] or null
     */
    public function archetypes(): ?array;
}
