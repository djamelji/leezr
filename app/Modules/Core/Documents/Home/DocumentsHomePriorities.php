<?php

namespace App\Modules\Core\Documents\Home;

use App\Core\Home\HomeContext;
use App\Core\Home\HomePriority;
use App\Core\Home\HomePriorityProvider;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;

/**
 * ADR-776 — Documents contribution to the company home. Counts come from the compliance read
 * model (the source of the Documents space itself) ; each item opens the matching treatment.
 * Reviewing needs `documents.manage` ; expiry follow-up needs `documents.view` (same gates as
 * the tabs they open).
 */
class DocumentsHomePriorities implements HomePriorityProvider
{
    public function priorities(HomeContext $home): array
    {
        if (! $home->can('documents.view')) {
            return [];
        }

        $summary = DocumentComplianceReadModel::forCompany($home->company)['summary'] ?? [];
        $tab = fn (string $tab, array $query = []) => ['name' => 'company-documents-tab', 'params' => ['tab' => $tab]] + ($query ? ['query' => $query] : []);
        $items = [];

        if ($home->can('documents.manage')) {
            $items[] = HomePriority::make('documents_to_review', (int) ($summary['pending_validation'] ?? 0), 'warning', 'tabler-file-check', $tab('todo'), 'documents');
        }
        $items[] = HomePriority::make('documents_expired', (int) ($summary['expired'] ?? 0), 'error', 'tabler-file-alert', $tab('compliance', ['status' => 'expired']), 'documents');
        $items[] = HomePriority::make('documents_expiring', (int) ($summary['expiring_soon'] ?? 0), 'warning', 'tabler-file-time', $tab('compliance', ['status' => 'expiring']), 'documents');

        return $items;
    }
}
