<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-173: Self-document download use case.
 *
 * Orchestrates: ownership → type → scope guard → document lookup.
 * Returns file metadata for controller to stream.
 */
class DownloadOwnDocumentUseCase
{
    public function execute(User $user, Company $company, string $documentCode): DownloadOwnDocumentResult
    {
        // 1. Verify membership exists for (company_id, user_id)
        $company->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        // 2. Load DocumentType by code
        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        // 3. Scope guard: self only handles company_user scope
        if ($type->scope !== DocumentType::SCOPE_COMPANY_USER) {
            abort(404);
        }

        // 4. Load MemberDocument for (company_id, user_id, document_type_id)
        $document = MemberDocument::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        return new DownloadOwnDocumentResult(
            filePath: $document->file_path,
            fileName: $document->file_name,
            disk: 'local',
        );
    }
}
