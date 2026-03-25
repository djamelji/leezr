<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentMandatoryContext;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;

/**
 * ADR-175: ReadModel for document activation admin catalog.
 *
 * Returns all system document types grouped by scope, with their
 * activation status for the given company. Market-filtered.
 */
class CompanyDocumentActivationReadModel
{
    public static function get(Company $company): array
    {
        // 1. All document types (system + company custom, excluding archived)
        $types = DocumentType::where(function ($q) use ($company) {
            $q->where('is_system', true)
                ->orWhere('company_id', $company->id);
        })->whereNull('archived_at')->get();

        // 2. Market filter
        $marketKey = $company->market_key;
        if ($marketKey) {
            $types = $types->filter(function ($type) use ($marketKey) {
                $markets = $type->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        if ($types->isEmpty()) {
            return [
                'company_user_documents' => [],
                'company_documents' => [],
            ];
        }

        // 3. Activations for this company
        $activations = DocumentTypeActivation::where('company_id', $company->id)
            ->whereIn('document_type_id', $types->pluck('id'))
            ->get()
            ->keyBy('document_type_id');

        // 4. Mandatory context (admin view — no role tags)
        $mandatoryContext = DocumentMandatoryContext::load($company->id);

        // 5. Build catalog
        $companyUserDocs = [];
        $companyDocs = [];

        foreach ($types as $type) {
            $activation = $activations->get($type->id);
            $rules = $type->validation_rules ?? [];

            $doc = [
                'code' => $type->code,
                'label' => $type->label,
                'scope' => $type->scope,
                'enabled' => $activation?->enabled ?? false,
                'required_override' => $activation?->required_override ?? false,
                'mandatory' => DocumentMandatoryContext::isMandatory($type, $mandatoryContext),
                'order' => $activation?->order ?? $type->default_order ?? 0,
                'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
                'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'png'],
                'applicable_markets' => $rules['applicable_markets'] ?? null,
                'is_system' => $type->is_system,
            ];

            // For custom types, include usage_count (actual uploads only — requests are cascade-deleted)
            if (! $type->is_system) {
                $doc['usage_count'] = MemberDocument::where('document_type_id', $type->id)->count()
                    + CompanyDocument::where('document_type_id', $type->id)->count();
            }

            if ($type->scope === DocumentType::SCOPE_COMPANY_USER) {
                $companyUserDocs[] = $doc;
            } else {
                $companyDocs[] = $doc;
            }
        }

        // 6. Sort by order
        usort($companyUserDocs, fn ($a, $b) => $a['order'] <=> $b['order']);
        usort($companyDocs, fn ($a, $b) => $a['order'] <=> $b['order']);

        return [
            'company_user_documents' => $companyUserDocs,
            'company_documents' => $companyDocs,
        ];
    }
}
