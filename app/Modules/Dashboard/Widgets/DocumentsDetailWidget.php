<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-745 — panneau composite « Documents à régulariser » (client-resolved).
 * Lit le store compliance existant (summary + by_type) pour montrer les documents
 * manquants/expirés et les types les plus exposés — sans toucher au backend documents.
 */
class DocumentsDetailWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'documents.detail'; }
    public function module(): string { return 'core.documents'; }
    public function layout(): array { return ['default_w' => 6, 'default_h' => 3, 'min_w' => 4, 'max_w' => 12, 'min_h' => 3, 'max_h' => 4]; }
    public function category(): string { return 'compliance'; }
    public function component(): string { return 'DocumentsDetail'; }
    public function tags(): array { return ['compliance', 'documents', 'overview']; }
    public function labelKey(): string { return 'compliance.widgets.detail'; }
    public function descriptionKey(): string { return 'compliance.widgets.detailDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'client'; }
    public function permissions(): array { return ['documents.view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management', 'operations_center']; }

    public function resolve(array $context): array { return []; }
}
