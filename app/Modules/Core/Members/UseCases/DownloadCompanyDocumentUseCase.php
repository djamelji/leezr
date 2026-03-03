<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-174: Company document download use case.
 *
 * Orchestrates: membership → type → scope guard → document lookup.
 * Returns file metadata for controller to stream.
 */
class DownloadCompanyDocumentUseCase
{
    public function execute(User $actor, Company $company, string $documentCode): DownloadCompanyDocumentResult
    {
        // 1. Verify actor membership (defense in depth)
        $company->memberships()
            ->where('user_id', $actor->id)
            ->firstOrFail();

        // 2. Load DocumentType by code
        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        // 3. Scope guard: company vault only handles company scope
        if ($type->scope !== DocumentType::SCOPE_COMPANY) {
            abort(404);
        }

        // 4. Load CompanyDocument for (company_id, document_type_id)
        $document = CompanyDocument::where('company_id', $company->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        return new DownloadCompanyDocumentResult(
            filePath: $document->file_path,
            fileName: $document->file_name,
            disk: 'local',
        );
    }
}
