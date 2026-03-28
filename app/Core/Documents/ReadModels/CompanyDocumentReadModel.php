<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;

/**
 * ADR-174: ReadModel for company document vault.
 *
 * Returns company-scope document types with their upload status.
 * Resolver provides type/market/activation filtering (no member uploads).
 * CompanyDocument hydration is handled here independently.
 */
class CompanyDocumentReadModel
{
    public static function get(Company $company): array
    {
        // 1. Resolve company-scope types (Q3 skipped — no member uploads)
        $documents = DocumentResolverService::resolve(
            user: null,
            companyId: $company->id,
            marketKey: $company->market_key,
            scope: DocumentType::SCOPE_COMPANY,
        );

        if (empty($documents)) {
            return ['documents' => []];
        }

        // 2. Load CompanyDocument uploads for this company (1 query)
        $typeIds = DocumentType::where(function ($q) use ($company) {
            $q->where('is_system', true)
                ->orWhere('company_id', $company->id);
        })->whereNull('archived_at')
            ->where('scope', DocumentType::SCOPE_COMPANY)
            ->pluck('id', 'code');

        $uploads = CompanyDocument::where('company_id', $company->id)
            ->get()
            ->keyBy('document_type_id');

        // 3. Enrich each document with upload status + lifecycle (ADR-384)
        $documents = array_map(function ($doc) use ($uploads, $typeIds) {
            $typeId = $typeIds->get($doc['code']);
            $upload = $typeId ? $uploads->get($typeId) : null;

            $doc['upload'] = $upload ? [
                'id' => $upload->id,
                'file_name' => $upload->file_name,
                'file_size_bytes' => $upload->file_size_bytes,
                'mime_type' => $upload->mime_type,
                'uploaded_at' => $upload->created_at->toIso8601String(),
                'expires_at' => $upload->expires_at?->toIso8601String(),
                'ocr_text' => $upload->ocr_text,
                'ai_analysis' => $upload->ai_analysis,
                'ai_insights' => $upload->ai_insights,
                'ai_suggestions' => $upload->ai_suggestions,
                'ai_status' => $upload->ai_status,
            ] : null;

            $doc['lifecycle_status'] = DocumentLifecycleService::computeFromDate(
                $upload !== null,
                $upload?->expires_at,
            );

            return $doc;
        }, $documents);

        return ['documents' => $documents];
    }
}
