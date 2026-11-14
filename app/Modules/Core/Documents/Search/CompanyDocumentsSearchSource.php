<?php

namespace App\Modules\Core\Documents\Search;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentLifecycleService as Lifecycle;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\Search\DocumentSearchQuery;
use App\Core\Documents\Search\DocumentSearchRow;
use App\Core\Documents\Search\DocumentSearchSource;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Home\HomeContext;

/** ADR-778 (UX3) — the company's own documents (vault). Same gate as the vault : `documents.view` + policy. */
class CompanyDocumentsSearchSource implements DocumentSearchSource
{
    public function key(): string
    {
        return 'company';
    }

    public function search(HomeContext $viewer, DocumentSearchQuery $query, int $limit): array
    {
        if (! $viewer->can('documents.view') || $query->personRef !== null || in_array($query->status, ['signed', 'pending_signature'], true)) {
            return ['rows' => [], 'truncated' => false];
        }

        $docs = CompanyDocument::withoutGlobalScopes()->where('company_id', $viewer->company->id)
            ->whereNotNull('file_path')->with('documentType:id,code,label')
            ->when($query->typeCode, fn ($q, $code) => $q->whereHas('documentType', fn ($t) => $t->where('code', $code)))
            ->when($query->like(), fn ($q, $like) => $q->where(fn ($w) => $w->where('file_name', 'like', $like)
                ->orWhereHas('documentType', fn ($t) => $t->where('label', 'like', $like)->orWhere('code', 'like', $like))))
            ->tap(fn ($q) => PersonDocumentsSearchSource::applyDates($q, $query))
            ->latest('updated_at')->limit($limit + 1)->get();

        $policy = app(DocumentAccessPolicy::class);
        $subject = DocumentSubject::company((int) $viewer->company->id);
        $rows = [];
        foreach ($docs->take($limit) as $doc) {
            $code = $doc->documentType?->code;
            if (! $policy->canAccess($viewer->user, $viewer->company, $subject, $code, DocumentAccessPolicy::OP_VIEW_METADATA)) {
                continue;
            }
            $rows[] = DocumentSearchRow::make('company', (int) $doc->id, $doc->documentType?->label ?: (string) $doc->file_name, $code, $doc->documentType?->label, null,
                PersonDocumentsSearchSource::status(Lifecycle::computeStatus(['expires_at' => $doc->expires_at])), $doc->expires_at?->toDateString(), $doc->updated_at?->toIso8601String(),
                ['name' => 'company-documents-tab', 'params' => ['tab' => 'company']], $code ? "/company/documents/{$code}/download" : null);
        }

        return ['rows' => $rows, 'truncated' => $docs->count() > $limit];
    }
}
