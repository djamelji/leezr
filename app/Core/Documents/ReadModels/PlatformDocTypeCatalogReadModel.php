<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Jobdomains\Jobdomain;

/**
 * ADR-182: ReadModel for platform document type catalog.
 *
 * Returns all system document types (active + archived) with usage stats.
 */
class PlatformDocTypeCatalogReadModel
{
    public static function index(): array
    {
        $types = DocumentType::where('is_system', true)
            ->whereNull('company_id')
            ->orderBy('scope')
            ->orderBy('default_order')
            ->orderBy('code')
            ->get();

        $typeIds = $types->pluck('id');

        // Batch counts
        $activationCounts = DocumentTypeActivation::whereIn('document_type_id', $typeIds)
            ->selectRaw('document_type_id, count(*) as cnt')
            ->groupBy('document_type_id')
            ->pluck('cnt', 'document_type_id');

        $memberDocCounts = MemberDocument::whereIn('document_type_id', $typeIds)
            ->selectRaw('document_type_id, count(*) as cnt')
            ->groupBy('document_type_id')
            ->pluck('cnt', 'document_type_id');

        $companyDocCounts = CompanyDocument::whereIn('document_type_id', $typeIds)
            ->selectRaw('document_type_id, count(*) as cnt')
            ->groupBy('document_type_id')
            ->pluck('cnt', 'document_type_id');

        return [
            'document_types' => $types->map(fn (DocumentType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'label' => $type->label,
                'scope' => $type->scope,
                'validation_rules' => $type->validation_rules ?? [],
                'default_order' => $type->default_order,
                'is_archived' => $type->archived_at !== null,
                'archived_at' => $type->archived_at?->toIso8601String(),
                'activations_count' => $activationCounts->get($type->id, 0),
                'member_documents_count' => $memberDocCounts->get($type->id, 0),
                'company_documents_count' => $companyDocCounts->get($type->id, 0),
            ])->values()->toArray(),
        ];
    }

    public static function show(int $id): array
    {
        $type = DocumentType::where('is_system', true)
            ->whereNull('company_id')
            ->findOrFail($id);

        $activationsCount = DocumentTypeActivation::where('document_type_id', $type->id)->count();
        $memberDocumentsCount = MemberDocument::where('document_type_id', $type->id)->count();
        $companyDocumentsCount = CompanyDocument::where('document_type_id', $type->id)->count();
        $requestsCount = DocumentRequest::where('document_type_id', $type->id)->count();

        // Find jobdomains that reference this type in their default_documents JSON
        $jobdomainPresets = Jobdomain::whereNotNull('default_documents')
            ->get()
            ->filter(function (Jobdomain $jd) use ($type) {
                $codes = array_column($jd->default_documents, 'code');

                return in_array($type->code, $codes, true);
            })
            ->map(fn (Jobdomain $jd) => [
                'id' => $jd->id,
                'key' => $jd->key,
                'label' => $jd->label,
            ])
            ->values()
            ->toArray();

        return [
            'document_type' => [
                'id' => $type->id,
                'code' => $type->code,
                'label' => $type->label,
                'scope' => $type->scope,
                'validation_rules' => $type->validation_rules ?? [],
                'default_order' => $type->default_order,
                'is_archived' => $type->archived_at !== null,
                'archived_at' => $type->archived_at?->toIso8601String(),
                'created_at' => $type->created_at?->toIso8601String(),
                'updated_at' => $type->updated_at?->toIso8601String(),
                'activations_count' => $activationsCount,
                'member_documents_count' => $memberDocumentsCount,
                'company_documents_count' => $companyDocumentsCount,
                'requests_count' => $requestsCount,
                'jobdomain_presets' => $jobdomainPresets,
            ],
        ];
    }
}
