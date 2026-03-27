<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Core\Documents\ReadModels\DocumentAiKpiReadService;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-413: AI KPI widget — Expirations detected + type mismatches.
 */
class AiDetectionsWidget implements WidgetManifest
{
    public function key(): string
    {
        return 'ai.detections';
    }

    public function module(): string
    {
        return 'documents';
    }

    public function labelKey(): string
    {
        return 'platformAi.widgets.detections';
    }

    public function descriptionKey(): string
    {
        return 'platformAi.widgets.detectionsDesc';
    }

    public function scope(): string
    {
        return 'global';
    }

    public function permissions(): array
    {
        return ['view_ai'];
    }

    public function capabilities(): array
    {
        return [];
    }

    public function defaultConfig(): array
    {
        return [];
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
        return 'ai';
    }

    public function tags(): array
    {
        return ['ai', 'documents', 'kpi'];
    }

    public function component(): string
    {
        return 'AiDetections';
    }

    public function audience(): string
    {
        return 'platform';
    }

    public function resolution(): string
    {
        return 'server';
    }

    public function datasetKey(): ?string
    {
        return 'ai.document_kpis';
    }

    public function resolve(array $context): array
    {
        return $this->transform(DocumentAiKpiReadService::loadDataset($context), $context);
    }

    public function transform(array $dataset, array $context): array
    {
        return [
            'key' => $this->key(),
            'data' => [
                'expirations' => $dataset['expirations_detected'] ?? 0,
                'mismatches' => $dataset['type_mismatches'] ?? 0,
            ],
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
