<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-173: ReadModel for self-document view.
 *
 * Returns documents ready to display in the self-upload UI.
 * Consumes DocumentResolverService and enriches with self-specific metadata.
 *
 * DETTE-DOC-001 resolved Phase 2 (ADR-174): scope parameter passed to resolver.
 */
class SelfDocumentReadModel
{
    public static function get(User $user, Company $company): array
    {
        // 1. Verify membership and load role
        $membership = $company->memberships()
            ->where('user_id', $user->id)
            ->with('companyRole:id,key')
            ->firstOrFail();

        $roleKey = $membership->companyRole?->key;

        // 2. Resolve documents via existing pipeline, scoped to company_user
        $documents = DocumentResolverService::resolve(
            $user,
            $company->id,
            $roleKey,
            $company->market_key,
            scope: DocumentType::SCOPE_COMPANY_USER,
        );

        if (empty($documents)) {
            return ['documents' => []];
        }

        // 3. Load workflow data so the member can see review status
        $typeCodes = array_column($documents, 'code');
        $typesByCode = DocumentType::whereIn('code', $typeCodes)->get()->keyBy('code');
        $typeIds = $typesByCode->pluck('id')->toArray();

        $requests = DocumentRequest::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereIn('document_type_id', $typeIds)
            ->get()
            ->keyBy('document_type_id');

        // 4. Enrich with self-specific UX metadata + workflow status
        foreach ($documents as &$doc) {
            $type = $typesByCode->get($doc['code']);
            $typeId = $type?->id;
            $request = $typeId ? $requests->get($typeId) : null;

            $doc['request_status'] = $request?->status;
            $doc['request_review_note'] = $request?->review_note;
            $doc['can_upload'] = true;
            $doc['can_delete'] = false;
        }

        unset($doc);

        return ['documents' => $documents];
    }
}
