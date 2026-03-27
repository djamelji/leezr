<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Core\Documents\ReadModels\DocumentAiKpiReadService;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-413: AI KPI widget — Auto-fills + auto-rejects executed.
 */
class AiAutoActionsWidget implements WidgetManifest
{
    public function key(): string
    {
        return 'ai.auto_actions';
    }

    public function module(): string
    {
        return 'documents';
    }

    public function labelKey(): string
    {
        return 'platformAi.widgets.autoActions';
    }

    public function descriptionKey(): string
    {
        return 'platformAi.widgets.autoActionsDesc';
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
        return 'AiAutoActions';
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
                'auto_fills' => $dataset['auto_fills'] ?? 0,
                'auto_rejects' => $dataset['auto_rejects'] ?? 0,
                'total' => $dataset['auto_actions_total'] ?? 0,
            ],
        ];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
