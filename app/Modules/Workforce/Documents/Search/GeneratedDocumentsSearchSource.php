<?php

namespace App\Modules\Workforce\Documents\Search;

use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\Search\DocumentSearchQuery;
use App\Core\Documents\Search\DocumentSearchRow;
use App\Core\Documents\Search\DocumentSearchSource;
use App\Core\Home\HomeContext;
use App\Modules\Workforce\Documents\Security\GeneratedDocumentAccess;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use Illuminate\Support\Facades\DB;

/**
 * ADR-778 (UX3) — GENERATED documents (contracts, attestations, payslips produced by Leezr). Same
 * authority as the HR registry : `workforce_documents.view`, then GeneratedDocumentAccess per row
 * (team perimeter + compensation : a document embedding a salary is not returned to someone who may
 * not read salaries). The signature state is the REAL one (ADR-773 rule, not duplicated).
 */
class GeneratedDocumentsSearchSource implements DocumentSearchSource
{
    public function key(): string
    {
        return 'generated';
    }

    public function search(HomeContext $viewer, DocumentSearchQuery $query, int $limit): array
    {
        if (! $viewer->can('workforce_documents.view') || in_array($query->status, ['valid', 'expiring', 'expired'], true) || $query->expiresBefore !== null) {
            return ['rows' => [], 'truncated' => false];
        }
        $companyId = (int) $viewer->company->id;
        $like = $query->like();
        $state = GeneratedDocumentReadModel::realSignatureStateSql();

        $builder = GeneratedDocument::withoutGlobalScopes()->where('generated_documents.company_id', $companyId)->with('template:id,code,name')
            ->when($query->typeCode, fn ($q, $code) => $q->whereHas('template', fn ($t) => $t->where('code', $code)))
            ->when($query->from, fn ($q, $d) => $q->where('generated_at', '>=', $d))
            ->when($query->to, fn ($q, $d) => $q->where('generated_at', '<=', $d))
            ->when($query->status === 'signed', fn ($q) => $q->whereRaw("{$state} = ?", [GeneratedDocument::SIGNATURE_SIGNED]))
            ->when($query->status === 'pending_signature', fn ($q) => $q->whereRaw("{$state} in ('pending', 'in_progress')"));

        if ($query->personRef !== null) {
            if (! preg_match('/^employee-(\d+)$/', $query->personRef, $m)) {
                return ['rows' => [], 'truncated' => false];
            }
            $builder->whereIn('generated_documents.id', array_column(GeneratedDocumentReadModel::forEmployee((int) $m[1], $companyId)->all(), 'id'));
        }
        if ($like !== null) {
            $employees = DB::table('workforce_employees')->where('company_id', $companyId)
                ->where(fn ($w) => \App\Modules\Core\Documents\Search\PersonDocumentsSearchSource::matchName($w, $query->term))->pluck('id');
            $contracts = DB::table('workforce_employment_contracts')->where('company_id', $companyId)->whereIn('employee_id', $employees)->pluck('id');
            $builder->where(fn ($w) => $w->whereHas('template', fn ($t) => $t->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhere(fn ($e) => $e->where('subject_type', 'employee')->whereIn('subject_id', $employees))
                ->orWhere(fn ($c) => $c->where('subject_type', 'contract')->whereIn('subject_id', $contracts)));
        }

        $docs = GeneratedDocumentReadModel::applyRealSignatureState($builder->latest('generated_at')->limit($limit * 3 + 1)->get(), $companyId);
        $allowed = [];
        foreach ($docs->take($limit * 3) as $doc) {
            if (GeneratedDocumentAccess::canOpen($viewer->request, $doc)) {
                $allowed[] = [$doc, GeneratedDocumentAccess::employeeIdOf($doc)];
            }
            if (count($allowed) >= $limit) {
                break;
            }
        }

        $names = DB::table('workforce_employees')->where('company_id', $companyId)->whereIn('id', array_filter(array_column($allowed, 1)))
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($e) => [(int) $e->id => trim("{$e->first_name} {$e->last_name}")]);
        $rows = [];
        foreach ($allowed as [$doc, $employeeId]) {
            $signature = (string) $doc->signature_status;
            $rows[] = DocumentSearchRow::make('generated', (int) $doc->id, $doc->template?->name ?: (string) $doc->template?->code, $doc->template?->code, $doc->template?->name,
                $employeeId ? ['ref' => "employee-{$employeeId}", 'name' => $names[$employeeId] ?? ''] : null,
                $signature === GeneratedDocument::SIGNATURE_SIGNED ? 'signed' : (in_array($signature, ['pending', 'in_progress'], true) ? 'pending_signature' : 'generated'),
                null, $doc->generated_at?->toIso8601String(),
                $employeeId ? ['name' => 'company-people-person-ref', 'params' => ['personRef' => "employee-{$employeeId}"], 'query' => ['tab' => 'documents']]
                    : ['name' => 'company-documents-tab', 'params' => ['tab' => 'people']],
                "/workforce/documents/{$doc->id}/download");
        }

        return ['rows' => $rows, 'truncated' => $docs->count() > $limit * 3 || count($allowed) >= $limit];
    }
}
